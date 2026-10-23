<?php

namespace App\Services\Sales;

use App\Models\Account;
use App\Models\Customer;
use App\Models\NumberSequence;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SalesReturn;
use App\Models\SalesReturnItem;
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
 * Sales returns are made against a posted invoice. The customer is credited the price they paid (after discount, with the
 * tax in proportion) and the goods go back into stock at the cost they LEFT at, so inventory and the journal agree to the paisa.
 *
 * Posting:  Dr Sales Returns (net)        Dr Sales Tax Payable (tax reversed)
 *           Cr Cash/Bank (refund paid now) Cr Accounts Receivable (credit note for the rest)
 *           Dr Inventory (per product)     Cr Cost of Goods Sold
 */
class SaleReturnService
{
    public function __construct(private InventoryService $inventory, private PostingService $posting)
    {
    }

    /**
     * What can still be returned, per product: quantity plus the net price, tax and cost still sitting in the invoice.
     *
     * @return array<int, array{product_id:int, sold:int, returned:int, available:int, net_left:int, tax_left:int, cogs_left:int}>  qty in thousandths, money in paisa
     */
    public function returnable(Sale $sale, ?int $ignoreReturnId = null): array
    {
        $out = [];
        foreach ($sale->items as $item) {
            $row = &$out[$item->product_id];
            $row ??= ['product_id' => $item->product_id, 'sold' => 0, 'returned' => 0, 'available' => 0, 'net_left' => 0, 'tax_left' => 0, 'cogs_left' => 0];
            $tax = Money::toCents($item->tax_amount);
            $row['sold'] += Qty::toMilli($item->qty);
            $row['available'] += Qty::toMilli($item->qty);
            $row['net_left'] += Money::toCents($item->line_total) - $tax;
            $row['tax_left'] += $tax;
            $row['cogs_left'] += Money::toCents($item->cogs_amount);
            unset($row);
        }

        $done = SalesReturnItem::query()
            ->join('sales_returns as r', 'r.id', '=', 'sales_return_items.sales_return_id')
            ->where('r.sale_id', $sale->id)->where('r.status', 'posted')
            ->when($ignoreReturnId, fn ($q) => $q->where('r.id', '!=', $ignoreReturnId))
            ->get(['sales_return_items.product_id', 'sales_return_items.qty', 'sales_return_items.tax_amount', 'sales_return_items.line_total', 'sales_return_items.cogs_amount']);

        foreach ($done as $d) {
            if (! isset($out[$d->product_id])) {
                continue;
            }
            $tax = Money::toCents($d->tax_amount);
            $out[$d->product_id]['returned'] += Qty::toMilli($d->qty);
            $out[$d->product_id]['available'] -= Qty::toMilli($d->qty);
            $out[$d->product_id]['net_left'] -= Money::toCents($d->line_total) - $tax;
            $out[$d->product_id]['tax_left'] -= $tax;
            $out[$d->product_id]['cogs_left'] -= Money::toCents($d->cogs_amount);
        }

        return $out;
    }

    /**
     * @param array<int, string|float|int> $qtyByProduct  product_id => quantity to return
     * @return array{lines: array<int, array>, net:int, tax:int, cogs:int, total:int}
     */
    public function calculate(Sale $sale, array $qtyByProduct, ?int $ignoreReturnId = null): array
    {
        $available = $this->returnable($sale->loadMissing('items'), $ignoreReturnId);
        $lines = [];
        $netTotal = $taxTotal = $cogsTotal = 0;

        foreach ($qtyByProduct as $productId => $qty) {
            $r = Qty::toMilli($qty);
            if ($r <= 0) {
                continue;
            }
            $a = $available[$productId] ?? throw new DomainException('That product is not on the original invoice.');
            if ($r > $a['available']) {
                $name = Product::withTrashed()->find($productId)?->name ?? "#{$productId}";
                throw new DomainException("Cannot return more than is left on the invoice: {$name} has " . Qty::fromMilli(max($a['available'], 0)) . ' left to return.');
            }

            // the last unit(s) take whatever is left, so repeated partial returns never leave paisa behind
            $share = fn (int $left) => $r === $a['available'] ? $left : (int) round($left * ($r / $a['available']));
            $net = $share($a['net_left']);
            $tax = $share($a['tax_left']);
            $cogs = $share($a['cogs_left']);

            $lines[] = [
                'product_id' => (int) $productId, 'qty' => Qty::fromMilli($r),
                'unit_price' => number_format(($net / 100) / ($r / 1000), 4, '.', ''),
                'unit_cost' => number_format(($cogs / 100) / ($r / 1000), 4, '.', ''),
                'tax_rate' => $net > 0 ? number_format($tax / $net * 100, 2, '.', '') : '0.00',
                'tax_amount' => Money::fromCents($tax), 'line_total' => Money::fromCents($net + $tax), 'cogs_amount' => Money::fromCents($cogs),
                'net' => $net, 'tax' => $tax, 'cogs' => $cogs,
            ];
            $netTotal += $net;
            $taxTotal += $tax;
            $cogsTotal += $cogs;
        }

        if (! $lines) {
            throw new DomainException('Enter a quantity for at least one product.');
        }

        return ['lines' => $lines, 'net' => $netTotal, 'tax' => $taxTotal, 'cogs' => $cogsTotal, 'total' => $netTotal + $taxTotal];
    }

    /** @param array{return_date:string, reason?:?string, notes?:?string, refund_now?:string|float|int|null, refund_account_id?:?int} $header */
    public function createDraft(Sale $sale, array $header, array $qtyByProduct): SalesReturn
    {
        if (! $sale->isPosted()) {
            throw new DomainException('Goods can only be returned against a posted invoice.');
        }
        $calc = $this->calculate($sale, $qtyByProduct);
        $this->assertSettlement($sale, $header, $calc['total']);

        return DB::transaction(function () use ($sale, $header, $calc) {
            $refund = Money::toCents($header['refund_now'] ?? 0);
            $return = new SalesReturn([
                'return_no' => NumberSequence::next('sales_return'), 'sale_id' => $sale->id, 'customer_id' => $sale->customer_id,
                'return_date' => Carbon::parse($header['return_date'])->toDateString(), 'reason' => $header['reason'] ?? null, 'notes' => $header['notes'] ?? null,
                'subtotal' => Money::fromCents($calc['net']), 'tax_total' => Money::fromCents($calc['tax']), 'total' => Money::fromCents($calc['total']),
                'refund_now' => Money::fromCents($refund), 'refund_account_id' => $refund > 0 ? ($header['refund_account_id'] ?? null) : null,
            ]);
            $return->forceFill(['status' => 'draft', 'created_by' => Auth::id()])->save();
            $return->items()->createMany(array_map(
                fn ($l) => collect($l)->only(['product_id', 'qty', 'unit_price', 'unit_cost', 'cogs_amount', 'tax_rate', 'tax_amount', 'line_total'])->all(),
                $calc['lines']
            ));

            return $return->load('items');
        });
    }

    public function post(SalesReturn $return): SalesReturn
    {
        return DB::transaction(function () use ($return) {
            $r = SalesReturn::with('items')->lockForUpdate()->findOrFail($return->id);
            if (! $r->isDraft()) {
                throw new DomainException('Only a draft return can be posted.');
            }
            $sale = Sale::with('items')->findOrFail($r->sale_id);
            if (! $sale->isPosted()) {
                throw new DomainException('The original invoice is no longer posted.');
            }

            // re-check against what is returnable NOW (another return may have been posted since this draft was saved)
            $calc = $this->calculate($sale, $r->items->pluck('qty', 'product_id')->all(), $r->id);
            $this->assertSettlement($sale, ['refund_now' => $r->refund_now, 'refund_account_id' => $r->refund_account_id, 'return_date' => $r->return_date], $calc['total']);

            $lines = [];
            foreach ($calc['lines'] as $line) {
                $product = Product::findOrFail($line['product_id']);
                $this->inventory->receive($product, $line['qty'], 'sale_return', $r->return_date, $r, totalCostCents: $line['cogs']);   // back in at the cost it left at
                if ($line['net'] > 0) {
                    $lines[] = ['account' => AccountCodes::SALES_RETURNS, 'debit' => Money::fromCents($line['net']), 'product_id' => $product->id];
                }
                if ($line['cogs'] > 0) {
                    $lines[] = ['account' => AccountCodes::INVENTORY, 'debit' => Money::fromCents($line['cogs']), 'product_id' => $product->id];
                }
            }
            if ($calc['tax'] > 0) {
                $lines[] = ['account' => AccountCodes::OUTPUT_TAX, 'debit' => Money::fromCents($calc['tax'])];
            }

            $refund = Money::toCents($r->refund_now);
            if ($refund > 0) {
                $lines[] = ['account' => (int) $r->refund_account_id, 'credit' => Money::fromCents($refund)];
            }
            $credit = $calc['total'] - $refund;
            if ($credit > 0) {
                $lines[] = ['account' => AccountCodes::RECEIVABLE, 'credit' => Money::fromCents($credit), 'party' => Customer::findOrFail($sale->customer_id)];
            }
            if ($calc['cogs'] > 0) {
                $lines[] = ['account' => AccountCodes::COGS, 'credit' => Money::fromCents($calc['cogs'])];
            }

            $entry = $this->posting->post([
                'date' => $r->return_date, 'voucher_type' => 'sales_return', 'reference' => $r->return_no, 'source' => $r,
                'description' => "Sales return {$r->return_no} against invoice {$sale->invoice_no}", 'lines' => $lines,
            ]);

            $r->forceFill(['status' => 'posted', 'journal_entry_id' => $entry->id, 'posted_at' => now(), 'updated_by' => Auth::id()])->save();

            return $r;
        });
    }

    public function cancel(SalesReturn $return, string $reason): SalesReturn
    {
        return DB::transaction(function () use ($return, $reason) {
            $r = SalesReturn::lockForUpdate()->findOrFail($return->id);
            if (! $r->isPosted()) {
                throw new DomainException('Only a posted return can be cancelled.');
            }

            $this->inventory->reverseSource($r);     // refused if the returned goods have since been sold again
            $reversal = $this->posting->reverse($r->journalEntry, $reason);

            $r->forceFill([
                'status' => 'cancelled', 'reversal_entry_id' => $reversal->id, 'cancelled_at' => now(),
                'cancelled_by' => Auth::id(), 'cancel_reason' => $reason, 'updated_by' => Auth::id(),
            ])->save();

            return $r;
        });
    }

    private function assertSettlement(Sale $sale, array $h, int $totalCents): void
    {
        if (Carbon::parse($h['return_date'])->lt($sale->sale_date)) {
            throw new DomainException('The return date cannot be before the invoice date.');
        }

        $refund = Money::toCents($h['refund_now'] ?? 0);
        if ($refund < 0 || $refund > $totalCents) {
            throw new DomainException('The refund cannot be negative or more than the value of the return.');
        }
        if ($refund > 0) {
            $account = ! empty($h['refund_account_id']) ? Account::find($h['refund_account_id']) : null;
            if (! $account || ! $account->is_active || ! in_array($account->sub_type, ['cash', 'bank'], true)) {
                throw new DomainException('Choose the cash or bank account the refund was paid from.');
            }
        }
    }
}
