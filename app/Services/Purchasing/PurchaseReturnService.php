<?php

namespace App\Services\Purchasing;

use App\Models\Account;
use App\Models\Employee;
use App\Models\NumberSequence;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnItem;
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
 * Purchase returns are always made against a posted purchase, and goods go back at the cost they were bought at
 * (so inventory value and the journal agree to the paisa, whatever the current average cost is).
 *
 * Posting:  Dr Cash/Bank (refund received now)   Dr Accounts Payable (supplier) / Employee Payable (credit note for the rest)
 *           Cr Inventory (per product)           Cr Sales Tax Receivable (input tax reversed)
 * A walk-in cash purchase has no supplier account to credit, so such a return must be refunded in full.
 */
class PurchaseReturnService
{
    public function __construct(private InventoryService $inventory, private PostingService $posting)
    {
    }

    /**
     * What can still be returned, per product: quantity and the cost / tax still sitting in the purchase.
     *
     * @return array<int, array{product_id:int, purchased:int, returned:int, available:int, net_left:int, tax_left:int}>  qty in thousandths, money in paisa
     */
    public function returnable(Purchase $purchase, ?int $ignoreReturnId = null): array
    {
        $out = [];
        foreach ($purchase->items as $item) {
            $row = &$out[$item->product_id];
            $row ??= ['product_id' => $item->product_id, 'purchased' => 0, 'returned' => 0, 'available' => 0, 'net_left' => 0, 'tax_left' => 0];
            $tax = Money::toCents($item->tax_amount);
            $row['purchased'] += Qty::toMilli($item->qty);
            $row['available'] += Qty::toMilli($item->qty);
            $row['net_left'] += Money::toCents($item->line_total) - $tax;
            $row['tax_left'] += $tax;
            unset($row);
        }

        $done = PurchaseReturnItem::query()
            ->join('purchase_returns as r', 'r.id', '=', 'purchase_return_items.purchase_return_id')
            ->where('r.purchase_id', $purchase->id)->where('r.status', 'posted')
            ->when($ignoreReturnId, fn ($q) => $q->where('r.id', '!=', $ignoreReturnId))
            ->get(['purchase_return_items.product_id', 'purchase_return_items.qty', 'purchase_return_items.tax_amount', 'purchase_return_items.line_total']);

        foreach ($done as $d) {
            if (! isset($out[$d->product_id])) {
                continue;
            }
            $tax = Money::toCents($d->tax_amount);
            $out[$d->product_id]['returned'] += Qty::toMilli($d->qty);
            $out[$d->product_id]['available'] -= Qty::toMilli($d->qty);
            $out[$d->product_id]['net_left'] -= Money::toCents($d->line_total) - $tax;
            $out[$d->product_id]['tax_left'] -= $tax;
        }

        return $out;
    }

    /**
     * @param array<int, string|float|int> $qtyByProduct  product_id => quantity to return
     * @return array{lines: array<int, array>, net:int, tax:int, total:int}
     */
    public function calculate(Purchase $purchase, array $qtyByProduct, ?int $ignoreReturnId = null): array
    {
        $available = $this->returnable($purchase->loadMissing('items'), $ignoreReturnId);
        $lines = [];
        $netTotal = $taxTotal = 0;

        foreach ($qtyByProduct as $productId => $qty) {
            $r = Qty::toMilli($qty);
            if ($r <= 0) {
                continue;
            }
            $a = $available[$productId] ?? throw new DomainException('That product is not on the original purchase.');
            if ($r > $a['available']) {
                $name = Product::withTrashed()->find($productId)?->name ?? "#{$productId}";
                throw new DomainException("Cannot return more than is left on the purchase: {$name} has " . Qty::fromMilli(max($a['available'], 0)) . ' left to return.');
            }

            // the last unit(s) take whatever cost is left, so repeated partial returns never leave paisa behind
            $net = $r === $a['available'] ? $a['net_left'] : (int) round($a['net_left'] * ($r / $a['available']));
            $tax = $r === $a['available'] ? $a['tax_left'] : (int) round($a['tax_left'] * ($r / $a['available']));

            $lines[] = [
                'product_id' => (int) $productId, 'qty' => Qty::fromMilli($r),
                'unit_cost' => number_format(($net / 100) / ($r / 1000), 4, '.', ''),
                'tax_rate' => $net > 0 ? number_format($tax / $net * 100, 2, '.', '') : '0.00',
                'tax_amount' => Money::fromCents($tax), 'line_total' => Money::fromCents($net + $tax), 'net' => $net, 'tax' => $tax,
            ];
            $netTotal += $net;
            $taxTotal += $tax;
        }

        if (! $lines) {
            throw new DomainException('Enter a quantity for at least one product.');
        }

        return ['lines' => $lines, 'net' => $netTotal, 'tax' => $taxTotal, 'total' => $netTotal + $taxTotal];
    }

    /** @param array{return_date:string, reason?:?string, notes?:?string, refund_now?:string|float|int|null, refund_account_id?:?int} $header */
    public function createDraft(Purchase $purchase, array $header, array $qtyByProduct): PurchaseReturn
    {
        if (! $purchase->isPosted()) {
            throw new DomainException('Goods can only be returned against a posted purchase.');
        }
        $calc = $this->calculate($purchase, $qtyByProduct);
        $this->assertSettlement($purchase, $header, $calc['total']);

        return DB::transaction(function () use ($purchase, $header, $calc) {
            $return = new PurchaseReturn([
                'return_no' => NumberSequence::next('purchase_return'), 'purchase_id' => $purchase->id, 'supplier_id' => $purchase->supplier_id,
                'return_date' => Carbon::parse($header['return_date'])->toDateString(), 'reason' => $header['reason'] ?? null, 'notes' => $header['notes'] ?? null,
                'subtotal' => Money::fromCents($calc['net']), 'tax_total' => Money::fromCents($calc['tax']), 'total' => Money::fromCents($calc['total']),
                'refund_now' => Money::fromCents(Money::toCents($header['refund_now'] ?? 0)),
                'refund_account_id' => Money::toCents($header['refund_now'] ?? 0) > 0 ? ($header['refund_account_id'] ?? null) : null,
            ]);
            $return->forceFill(['status' => 'draft', 'created_by' => Auth::id()])->save();
            $return->items()->createMany(array_map(fn ($l) => collect($l)->only(['product_id', 'qty', 'unit_cost', 'tax_rate', 'tax_amount', 'line_total'])->all(), $calc['lines']));

            return $return->load('items');
        });
    }

    public function post(PurchaseReturn $return): PurchaseReturn
    {
        return DB::transaction(function () use ($return) {
            $r = PurchaseReturn::with('items')->lockForUpdate()->findOrFail($return->id);
            if (! $r->isDraft()) {
                throw new DomainException('Only a draft return can be posted.');
            }
            $purchase = Purchase::with('items')->findOrFail($r->purchase_id);
            if (! $purchase->isPosted()) {
                throw new DomainException('The original purchase is no longer posted.');
            }

            // re-check against what is returnable NOW (another return may have been posted since this draft was saved)
            $calc = $this->calculate($purchase, $r->items->pluck('qty', 'product_id')->all(), $r->id);
            $this->assertSettlement($purchase, ['refund_now' => $r->refund_now, 'refund_account_id' => $r->refund_account_id, 'return_date' => $r->return_date], $calc['total']);

            $lines = [];
            $refund = Money::toCents($r->refund_now);
            if ($refund > 0) {
                $lines[] = ['account' => (int) $r->refund_account_id, 'debit' => Money::fromCents($refund)];
            }
            $credit = $calc['total'] - $refund;
            if ($credit > 0) {
                $lines[] = $purchase->funded_by_employee_id
                    ? ['account' => AccountCodes::EMPLOYEE_PAYABLE, 'debit' => Money::fromCents($credit), 'party' => Employee::findOrFail($purchase->funded_by_employee_id)]
                    : ['account' => AccountCodes::PAYABLE, 'debit' => Money::fromCents($credit), 'party' => Supplier::findOrFail($purchase->supplier_id)];
            }

            foreach ($calc['lines'] as $line) {
                $product = Product::findOrFail($line['product_id']);
                $this->inventory->issue($product, $line['qty'], 'purchase_return', $r->return_date, $r, totalCostCents: $line['net']);   // refused if the stock is no longer there
                if ($line['net'] > 0) {
                    $lines[] = ['account' => AccountCodes::INVENTORY, 'credit' => Money::fromCents($line['net']), 'product_id' => $product->id];
                }
            }
            if ($calc['tax'] > 0) {
                $lines[] = ['account' => AccountCodes::INPUT_TAX, 'credit' => Money::fromCents($calc['tax'])];
            }

            $entry = $this->posting->post([
                'date' => $r->return_date, 'voucher_type' => 'purchase_return', 'reference' => $r->return_no, 'source' => $r,
                'description' => "Purchase return {$r->return_no} against {$purchase->purchase_no}", 'lines' => $lines,
            ]);

            $r->forceFill(['status' => 'posted', 'journal_entry_id' => $entry->id, 'posted_at' => now(), 'updated_by' => Auth::id()])->save();

            return $r;
        });
    }

    public function cancel(PurchaseReturn $return, string $reason): PurchaseReturn
    {
        return DB::transaction(function () use ($return, $reason) {
            $r = PurchaseReturn::lockForUpdate()->findOrFail($return->id);
            if (! $r->isPosted()) {
                throw new DomainException('Only a posted return can be cancelled.');
            }

            $this->inventory->reverseSource($r);     // goods come back into stock at the cost they left at
            $reversal = $this->posting->reverse($r->journalEntry, $reason);

            $r->forceFill([
                'status' => 'cancelled', 'reversal_entry_id' => $reversal->id, 'cancelled_at' => now(),
                'cancelled_by' => Auth::id(), 'cancel_reason' => $reason, 'updated_by' => Auth::id(),
            ])->save();

            return $r;
        });
    }

    private function assertSettlement(Purchase $purchase, array $h, int $totalCents): void
    {
        if (Carbon::parse($h['return_date'])->lt($purchase->purchase_date)) {
            throw new DomainException('The return date cannot be before the purchase date.');
        }

        $refund = Money::toCents($h['refund_now'] ?? 0);
        if ($refund < 0 || $refund > $totalCents) {
            throw new DomainException('The refund cannot be negative or more than the value of the return.');
        }
        if ($refund > 0) {
            $account = ! empty($h['refund_account_id']) ? Account::find($h['refund_account_id']) : null;
            if (! $account || ! $account->is_active || ! in_array($account->sub_type, ['cash', 'bank'], true)) {
                throw new DomainException('Choose the cash or bank account the refund was received into.');
            }
        }
        $hasParty = $purchase->supplier_id || $purchase->funded_by_employee_id;
        if (! $hasParty && $refund !== $totalCents) {
            throw new DomainException('This purchase has no supplier account, so the return must be refunded in full (cash or bank).');
        }
    }
}
