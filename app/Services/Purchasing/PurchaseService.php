<?php

namespace App\Services\Purchasing;

use App\Models\Account;
use App\Models\Employee;
use App\Models\NumberSequence;
use App\Models\PaymentAllocation;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Services\Accounting\PostingService;
use App\Services\Inventory\InventoryService;
use App\Support\AccountCodes;
use App\Support\Money;
use App\Support\Qty;
use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Purchase lifecycle: draft -> posted -> cancelled.
 *
 * Posting (one DB transaction): stock in at the line's net cost (after discount, before tax) -> weighted average updates,
 * and ONE balanced journal entry:
 *   Dr Inventory (per line, net)        Dr Sales Tax Receivable (input tax, if any)
 *   Cr Cash/Bank (paid now)             Cr Accounts Payable (supplier)  -- or Employee Payable when an employee paid from his own pocket
 * A purchase on credit therefore needs a supplier (or the employee who funded it); a walk-in cash purchase needs neither.
 */
class PurchaseService
{
    public function __construct(private InventoryService $inventory, private PostingService $posting)
    {
    }

    /**
     * Pure arithmetic, exact in paisa. Line cost = qty x unit cost - discount; tax = rate% of that net.
     *
     * @param array<int, array{product_id:int, qty:string|float|int, unit_cost?:string|float|int|null, discount?:string|float|int|null, tax_rate?:string|float|int|null}> $items
     * @return array{lines: array<int, array>, subtotal:int, discount:int, tax:int, total:int}
     */
    public function calculate(array $items): array
    {
        $lines = [];
        $gross = $discount = $tax = 0;

        foreach (array_values($items) as $i => $item) {
            $n = $i + 1;
            $qtyMilli = Qty::toMilli($item['qty'] ?? 0);
            if ($qtyMilli <= 0) {
                throw new DomainException("Line {$n}: quantity must be greater than zero.");
            }

            $lineGross = Money::lineCents($item['qty'], $item['unit_cost'] ?? 0);
            $lineDiscount = Money::toCents($item['discount'] ?? 0);
            if ($lineDiscount < 0 || $lineDiscount > $lineGross) {
                throw new DomainException("Line {$n}: the discount cannot be more than the line amount.");
            }

            $net = $lineGross - $lineDiscount;
            $rate = Money::toScaled($item['tax_rate'] ?? 0, 2);
            $lineTax = Money::percentOf($net, Money::fromScaled($rate, 2));

            $lines[] = [
                'product_id' => (int) $item['product_id'], 'qty' => Qty::fromMilli($qtyMilli),
                'unit_cost' => Money::fromScaled(Money::toScaled($item['unit_cost'] ?? 0, 4), 4),
                'discount' => Money::fromCents($lineDiscount), 'tax_rate' => Money::fromScaled($rate, 2),
                'tax_amount' => Money::fromCents($lineTax), 'line_total' => Money::fromCents($net + $lineTax),
                'net' => $net, 'tax' => $lineTax,
            ];
            $gross += $lineGross;
            $discount += $lineDiscount;
            $tax += $lineTax;
        }

        return ['lines' => $lines, 'subtotal' => $gross, 'discount' => $discount, 'tax' => $tax, 'total' => $gross - $discount + $tax];
    }

    /** @param array<string,mixed> $header @param array<int,array> $items */
    public function createDraft(array $header, array $items): Purchase
    {
        $calc = $this->calculate($items);
        $this->assertSettlement($header, $calc['total']);

        return DB::transaction(function () use ($header, $calc) {
            $purchase = new Purchase($this->headerAttributes($header, $calc) + ['purchase_no' => NumberSequence::next('purchase')]);
            $purchase->forceFill(['status' => 'draft', 'created_by' => Auth::id()])->save();
            $purchase->items()->createMany($this->itemRows($calc));

            return $purchase->load('items');
        });
    }

    public function updateDraft(Purchase $purchase, array $header, array $items): Purchase
    {
        $calc = $this->calculate($items);
        $this->assertSettlement($header, $calc['total'], $purchase->id);

        return DB::transaction(function () use ($purchase, $header, $calc) {
            $p = Purchase::lockForUpdate()->findOrFail($purchase->id);
            if (! $p->isDraft()) {
                throw new DomainException('Only a draft purchase can be edited. Cancel a posted purchase and enter it again.');
            }

            $p->fill($this->headerAttributes($header, $calc));
            $p->forceFill(['updated_by' => Auth::id()])->save();
            $p->items()->delete();
            $p->items()->createMany($this->itemRows($calc));

            return $p->load('items');
        });
    }

    public function post(Purchase $purchase): Purchase
    {
        return DB::transaction(function () use ($purchase) {
            $p = Purchase::with('items')->lockForUpdate()->findOrFail($purchase->id);
            if (! $p->isDraft()) {
                throw new DomainException('Only a draft purchase can be posted.');
            }

            $calc = $this->calculate($p->items->map(fn ($i) => [
                'product_id' => $i->product_id, 'qty' => $i->qty, 'unit_cost' => $i->unit_cost, 'discount' => $i->discount, 'tax_rate' => $i->tax_rate,
            ])->all());
            $header = $p->only(['supplier_id', 'funded_by_employee_id', 'supplier_ref', 'paid_now', 'paid_from_account_id']);
            $this->assertSettlement($header, $calc['total'], $p->id);

            $lines = [];
            foreach ($calc['lines'] as $line) {
                $product = Product::findOrFail($line['product_id']);
                $this->inventory->receive($product, $line['qty'], 'purchase', $p->purchase_date, $p, totalCostCents: $line['net']);
                if ($line['net'] > 0) {
                    $lines[] = ['account' => AccountCodes::INVENTORY, 'debit' => Money::fromCents($line['net']), 'product_id' => $product->id];
                }
            }
            if ($calc['tax'] > 0) {
                $lines[] = ['account' => AccountCodes::INPUT_TAX, 'debit' => Money::fromCents($calc['tax'])];
            }

            $paid = Money::toCents($p->paid_now);
            if ($paid > 0) {
                $lines[] = ['account' => (int) $p->paid_from_account_id, 'credit' => Money::fromCents($paid)];
            }
            $unpaid = $calc['total'] - $paid;
            if ($unpaid > 0) {
                $lines[] = $p->funded_by_employee_id
                    ? ['account' => AccountCodes::EMPLOYEE_PAYABLE, 'credit' => Money::fromCents($unpaid), 'party' => Employee::findOrFail($p->funded_by_employee_id)]
                    : ['account' => AccountCodes::PAYABLE, 'credit' => Money::fromCents($unpaid), 'party' => Supplier::findOrFail($p->supplier_id)];
            }

            $entry = $this->posting->post([
                'date' => $p->purchase_date, 'voucher_type' => 'purchase', 'reference' => $p->supplier_ref ?: $p->purchase_no, 'source' => $p,
                'description' => "Purchase {$p->purchase_no}" . ($p->supplier_id ? ' from ' . Supplier::find($p->supplier_id)?->name : ' (cash purchase)'),
                'lines' => $lines,
            ]);

            $p->fill(['subtotal' => Money::fromCents($calc['subtotal']), 'discount_total' => Money::fromCents($calc['discount']),
                      'tax_total' => Money::fromCents($calc['tax']), 'total' => Money::fromCents($calc['total'])]);
            $p->forceFill(['status' => 'posted', 'journal_entry_id' => $entry->id, 'posted_at' => now(), 'updated_by' => Auth::id()])->save();

            return $p;
        });
    }

    public function cancel(Purchase $purchase, string $reason): Purchase
    {
        return DB::transaction(function () use ($purchase, $reason) {
            $p = Purchase::lockForUpdate()->findOrFail($purchase->id);
            if (! $p->isPosted()) {
                throw new DomainException('Only a posted purchase can be cancelled.');
            }
            if (PaymentAllocation::where('target_type', 'purchase')->where('target_id', $p->id)->where('is_active', true)->exists()) {
                throw new DomainException('Payments have been applied to this purchase. Cancel those payments first.');
            }
            if ($p->returns()->where('status', 'posted')->exists()) {
                throw new DomainException('This purchase has returns. Cancel the returns first.');
            }

            $this->inventory->reverseSource($p);              // refused if the purchased stock has already been used
            $reversal = $this->posting->reverse($p->journalEntry, $reason);

            $p->forceFill([
                'status' => 'cancelled', 'reversal_entry_id' => $reversal->id, 'cancelled_at' => now(),
                'cancelled_by' => Auth::id(), 'cancel_reason' => $reason, 'updated_by' => Auth::id(),
            ])->save();

            return $p;
        });
    }

    // ------------------------------------------------------------------ internals

    /** Business rules that depend on the total: settlement, party, duplicate supplier invoice. */
    private function assertSettlement(array $h, int $totalCents, ?int $ignoreId = null): void
    {
        if ($totalCents <= 0) {
            throw new DomainException('The purchase total must be greater than zero.');
        }

        $paid = Money::toCents($h['paid_now'] ?? 0);
        if ($paid < 0 || $paid > $totalCents) {
            throw new DomainException('The amount paid now cannot be negative or more than the purchase total.');
        }
        if ($paid > 0) {
            $account = ! empty($h['paid_from_account_id']) ? Account::find($h['paid_from_account_id']) : null;
            if (! $account || ! $account->is_active || ! in_array($account->sub_type, ['cash', 'bank'], true)) {
                throw new DomainException('Choose the cash or bank account the payment was made from.');
            }
        }
        if ($totalCents - $paid > 0 && empty($h['supplier_id']) && empty($h['funded_by_employee_id'])) {
            throw new DomainException('A purchase that is not paid in full needs a supplier (or the employee who paid for it).');
        }

        if (! empty($h['supplier_id']) && ! empty($h['supplier_ref'])) {
            $dup = Purchase::where('supplier_id', $h['supplier_id'])->where('supplier_ref', $h['supplier_ref'])
                ->where('status', '!=', 'cancelled')->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->first();
            if ($dup) {
                throw new DomainException("Supplier invoice {$h['supplier_ref']} is already entered as {$dup->purchase_no}.");
            }
        }
    }

    private function headerAttributes(array $h, array $calc): array
    {
        $supplier = ! empty($h['supplier_id']) ? Supplier::find($h['supplier_id']) : null;
        $date = Carbon::parse($h['purchase_date']);
        $due = ! empty($h['due_date']) ? $h['due_date'] : ($supplier ? $date->copy()->addDays((int) $supplier->payment_terms_days)->toDateString() : null);

        return [
            'supplier_id' => $h['supplier_id'] ?? null, 'funded_by_employee_id' => $h['funded_by_employee_id'] ?? null,
            'purchase_date' => $date->toDateString(), 'due_date' => $due, 'supplier_ref' => $h['supplier_ref'] ?? null, 'notes' => $h['notes'] ?? null,
            'paid_now' => Money::fromCents(Money::toCents($h['paid_now'] ?? 0)), 'paid_from_account_id' => Money::toCents($h['paid_now'] ?? 0) > 0 ? ($h['paid_from_account_id'] ?? null) : null,
            'subtotal' => Money::fromCents($calc['subtotal']), 'discount_total' => Money::fromCents($calc['discount']),
            'tax_total' => Money::fromCents($calc['tax']), 'total' => Money::fromCents($calc['total']),
        ];
    }

    private function itemRows(array $calc): array
    {
        return array_map(fn ($l) => collect($l)->only(['product_id', 'qty', 'unit_cost', 'discount', 'tax_rate', 'tax_amount', 'line_total'])->all(), $calc['lines']);
    }
}
