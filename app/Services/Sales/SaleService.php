<?php

namespace App\Services\Sales;

use App\Models\Account;
use App\Models\Customer;
use App\Models\NumberSequence;
use App\Models\PaymentAllocation;
use App\Models\Product;
use App\Models\Sale;
use App\Services\Accounting\PostingService;
use App\Services\Inventory\InventoryService;
use App\Support\AccountCodes;
use App\Support\Money;
use App\Support\Qty;
use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Sale (tax invoice) lifecycle: draft -> posted -> cancelled.
 *
 * Posting is ONE database transaction that:
 *   1. takes the goods out of stock at the moving-average cost (refused if there isn't enough, unless negative stock is enabled),
 *   2. assigns the next invoice number (so drafts and deleted drafts never leave gaps),
 *   3. posts ONE balanced journal entry:
 *        Dr Cash/Bank (received now)   Dr Accounts Receivable (customer, the rest)   Dr Sales Discounts
 *        Cr Sales (per line, gross)    Cr Sales Tax Payable (output tax)
 *        Dr Cost of Goods Sold         Cr Inventory (per line, at cost)
 * Tax rate per line: leave it blank to use the customer's rate (0% if exempt), enter 0 to override.
 */
class SaleService
{
    public function __construct(private InventoryService $inventory, private PostingService $posting)
    {
    }

    /**
     * Pure arithmetic, exact in paisa. net = qty x price - discount; tax = rate% of net.
     *
     * @param array<int, array{product_id:int, qty:string|float|int, unit_price?:string|float|int|null, discount?:string|float|int|null, tax_rate?:string|float|int|null, description?:?string}> $items
     * @return array{lines: array<int, array>, gross:int, discount:int, tax:int, total:int}
     */
    public function calculate(array $items, string|float|int $defaultTaxRate = 0): array
    {
        $lines = [];
        $gross = $discount = $tax = 0;

        foreach (array_values($items) as $i => $item) {
            $n = $i + 1;
            if (Qty::toMilli($item['qty'] ?? 0) <= 0) {
                throw new DomainException("Line {$n}: quantity must be greater than zero.");
            }

            $lineGross = Money::lineCents($item['qty'], $item['unit_price'] ?? 0);
            $lineDiscount = Money::toCents($item['discount'] ?? 0);
            if ($lineDiscount < 0 || $lineDiscount > $lineGross) {
                throw new DomainException("Line {$n}: the discount cannot be more than the line amount.");
            }

            $net = $lineGross - $lineDiscount;
            $rateRaw = $item['tax_rate'] ?? null;
            $rate = Money::toScaled(($rateRaw === null || $rateRaw === '') ? $defaultTaxRate : $rateRaw, 2);
            $lineTax = Money::percentOf($net, Money::fromScaled($rate, 2));

            $lines[] = [
                'product_id' => (int) $item['product_id'], 'description' => $item['description'] ?? null, 'qty' => Qty::fromMilli(Qty::toMilli($item['qty'])),
                'unit_price' => Money::fromScaled(Money::toScaled($item['unit_price'] ?? 0, 4), 4),
                'discount' => Money::fromCents($lineDiscount), 'tax_rate' => Money::fromScaled($rate, 2),
                'tax_amount' => Money::fromCents($lineTax), 'line_total' => Money::fromCents($net + $lineTax),
                'gross' => $lineGross, 'net' => $net, 'tax' => $lineTax,
            ];
            $gross += $lineGross;
            $discount += $lineDiscount;
            $tax += $lineTax;
        }

        return ['lines' => $lines, 'gross' => $gross, 'discount' => $discount, 'tax' => $tax, 'total' => $gross - $discount + $tax];
    }

    /** @param array<string,mixed> $header @param array<int,array> $items */
    public function createDraft(array $header, array $items): Sale
    {
        $customer = Customer::findOrFail($header['customer_id']);
        $calc = $this->calculate($items, $customer->effectiveTaxRate());
        $this->assertSettlement($header, $calc['total']);

        return DB::transaction(function () use ($header, $calc, $customer) {
            $sale = new Sale($this->headerAttributes($header, $calc, $customer) + ['invoice_no' => 'DRAFT-' . Str::random(12)]);
            $sale->forceFill(['status' => 'draft', 'created_by' => Auth::id()])->save();
            $sale->items()->createMany($this->itemRows($calc));

            return $sale->load('items');
        });
    }

    public function updateDraft(Sale $sale, array $header, array $items): Sale
    {
        $customer = Customer::findOrFail($header['customer_id']);
        $calc = $this->calculate($items, $customer->effectiveTaxRate());
        $this->assertSettlement($header, $calc['total']);

        return DB::transaction(function () use ($sale, $header, $calc, $customer) {
            $s = Sale::lockForUpdate()->findOrFail($sale->id);
            if (! $s->isDraft()) {
                throw new DomainException('Only a draft sale can be edited. Cancel a posted invoice and enter it again.');
            }

            $s->fill($this->headerAttributes($header, $calc, $customer));
            $s->forceFill(['updated_by' => Auth::id()])->save();
            $s->items()->delete();
            $s->items()->createMany($this->itemRows($calc));

            return $s->load('items');
        });
    }

    public function post(Sale $sale): Sale
    {
        return DB::transaction(function () use ($sale) {
            $s = Sale::with('items')->lockForUpdate()->findOrFail($sale->id);
            if (! $s->isDraft()) {
                throw new DomainException('Only a draft sale can be posted.');
            }
            $customer = Customer::findOrFail($s->customer_id);

            $calc = $this->calculate($s->items->map(fn ($i) => [
                'product_id' => $i->product_id, 'description' => $i->description, 'qty' => $i->qty, 'unit_price' => $i->unit_price,
                'discount' => $i->discount, 'tax_rate' => $i->tax_rate,
            ])->all(), $customer->effectiveTaxRate());
            $this->assertSettlement($s->only(['received_now', 'received_into_account_id']), $calc['total']);

            $received = Money::toCents($s->received_now);
            $unpaid = $calc['total'] - $received;
            $this->assertCreditLimit($customer, $unpaid);

            $revenueLines = $cogsLines = [];
            $cogsTotal = 0;
            foreach ($s->items as $idx => $item) {
                $line = $calc['lines'][$idx];
                $product = Product::findOrFail($item->product_id);

                $row = $this->inventory->issue($product, $line['qty'], 'sale', $s->sale_date, $s);   // throws if there is not enough stock
                $cost = Money::toCents($row->total_cost);
                $cogsTotal += $cost;
                $item->forceFill(['unit_cost' => $row->unit_cost, 'cogs_amount' => $row->total_cost])->save();

                if ($line['gross'] > 0) {
                    $revenueLines[] = ['account' => AccountCodes::SALES, 'credit' => Money::fromCents($line['gross']), 'product_id' => $product->id];
                }
                if ($cost > 0) {
                    $cogsLines[] = ['account' => AccountCodes::INVENTORY, 'credit' => Money::fromCents($cost), 'product_id' => $product->id];
                }
            }

            $invoiceNo = NumberSequence::next('sale');

            $lines = [];
            if ($received > 0) {
                $lines[] = ['account' => (int) $s->received_into_account_id, 'debit' => Money::fromCents($received)];
            }
            if ($unpaid > 0) {
                $lines[] = ['account' => AccountCodes::RECEIVABLE, 'debit' => Money::fromCents($unpaid), 'party' => $customer];
            }
            if ($calc['discount'] > 0) {
                $lines[] = ['account' => AccountCodes::SALES_DISCOUNT, 'debit' => Money::fromCents($calc['discount'])];
            }
            array_push($lines, ...$revenueLines);
            if ($calc['tax'] > 0) {
                $lines[] = ['account' => AccountCodes::OUTPUT_TAX, 'credit' => Money::fromCents($calc['tax'])];
            }
            if ($cogsTotal > 0) {
                $lines[] = ['account' => AccountCodes::COGS, 'debit' => Money::fromCents($cogsTotal)];
                array_push($lines, ...$cogsLines);
            }

            $entry = $this->posting->post([
                'date' => $s->sale_date, 'voucher_type' => 'sale', 'reference' => $invoiceNo, 'source' => $s,
                'description' => "Sales invoice {$invoiceNo} to {$customer->name}", 'lines' => $lines,
            ]);

            $s->fill([
                'invoice_no' => $invoiceNo, 'subtotal' => Money::fromCents($calc['gross']), 'discount_total' => Money::fromCents($calc['discount']),
                'tax_total' => Money::fromCents($calc['tax']), 'total' => Money::fromCents($calc['total']), 'cogs_total' => Money::fromCents($cogsTotal),
            ] + $this->buyerSnapshot($customer));
            $s->forceFill(['status' => 'posted', 'journal_entry_id' => $entry->id, 'posted_at' => now(), 'updated_by' => Auth::id()])->save();

            return $s;
        });
    }

    public function cancel(Sale $sale, string $reason): Sale
    {
        return DB::transaction(function () use ($sale, $reason) {
            $s = Sale::lockForUpdate()->findOrFail($sale->id);
            if (! $s->isPosted()) {
                throw new DomainException('Only a posted invoice can be cancelled.');
            }
            if (PaymentAllocation::where('target_type', 'sale')->where('target_id', $s->id)->where('is_active', true)->exists()) {
                throw new DomainException('Receipts have been applied to this invoice. Cancel those receipts first.');
            }
            if ($s->returns()->where('status', 'posted')->exists()) {
                throw new DomainException('This invoice has sales returns. Cancel the returns first.');
            }

            $this->inventory->reverseSource($s);          // goods go back into stock at the cost they left at
            $reversal = $this->posting->reverse($s->journalEntry, $reason);

            $s->forceFill([
                'status' => 'cancelled', 'reversal_entry_id' => $reversal->id, 'cancelled_at' => now(),
                'cancelled_by' => Auth::id(), 'cancel_reason' => $reason, 'updated_by' => Auth::id(),
            ])->save();

            return $s;       // the invoice number is kept: a cancelled invoice still exists (printed as CANCELLED)
        });
    }

    // ------------------------------------------------------------------ internals

    private function assertSettlement(array $h, int $totalCents): void
    {
        if ($totalCents <= 0) {
            throw new DomainException('The invoice total must be greater than zero.');
        }

        $received = Money::toCents($h['received_now'] ?? 0);
        if ($received < 0 || $received > $totalCents) {
            throw new DomainException('The amount received now cannot be negative or more than the invoice total.');
        }
        if ($received > 0) {
            $account = ! empty($h['received_into_account_id']) ? Account::find($h['received_into_account_id']) : null;
            if (! $account || ! $account->is_active || ! in_array($account->sub_type, ['cash', 'bank'], true)) {
                throw new DomainException('Choose the cash or bank account the payment was received into.');
            }
        }
    }

    /** Credit limit 0 = unlimited. Otherwise what the customer already owes + this sale's unpaid part must stay within it. */
    private function assertCreditLimit(Customer $customer, int $unpaidCents): void
    {
        $limit = Money::toCents($customer->credit_limit);
        if ($limit <= 0 || $unpaidCents <= 0) {
            return;
        }

        $owes = Money::toCents($customer->ledgerBalance());
        if ($owes + $unpaidCents > $limit) {
            throw new DomainException(sprintf(
                'Credit limit exceeded for %s: already owes %s, this invoice adds %s, limit is %s.',
                $customer->name, Money::fromCents($owes), Money::fromCents($unpaidCents), Money::fromCents($limit)
            ));
        }
    }

    private function buyerSnapshot(Customer $c): array
    {
        return ['buyer_name' => $c->name, 'buyer_address' => $c->address, 'buyer_ntn' => $c->ntn, 'buyer_strn' => $c->strn];
    }

    private function headerAttributes(array $h, array $calc, Customer $customer): array
    {
        $date = Carbon::parse($h['sale_date']);
        $due = ! empty($h['due_date']) ? $h['due_date'] : $date->copy()->addDays((int) $customer->payment_terms_days)->toDateString();
        $received = Money::toCents($h['received_now'] ?? 0);

        return [
            'customer_id' => $customer->id, 'sale_date' => $date->toDateString(), 'due_date' => $due,
            'gate_pass_no' => $h['gate_pass_no'] ?? null, 'notes' => $h['notes'] ?? null,
            'received_now' => Money::fromCents($received), 'received_into_account_id' => $received > 0 ? ($h['received_into_account_id'] ?? null) : null,
            'subtotal' => Money::fromCents($calc['gross']), 'discount_total' => Money::fromCents($calc['discount']),
            'tax_total' => Money::fromCents($calc['tax']), 'total' => Money::fromCents($calc['total']),
        ] + $this->buyerSnapshot($customer);
    }

    private function itemRows(array $calc): array
    {
        return array_map(fn ($l) => collect($l)->only(['product_id', 'description', 'qty', 'unit_price', 'discount', 'tax_rate', 'tax_amount', 'line_total'])->all(), $calc['lines']);
    }
}
