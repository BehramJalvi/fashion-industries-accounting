<?php

namespace App\Services\Reports;

use App\Models\InventoryTransaction;
use App\Models\JournalEntryLine;
use App\Models\Product;
use App\Support\AccountCodes;
use App\Support\Money;
use App\Support\Qty;

/**
 * Stock valuation at weighted-average cost, from the stock ledger: quantity, average cost and value per product, with a control
 * check against the Inventory account. For a past date the last ledger row on or before that date is used (ledger chain order).
 */
class StockValuationService
{
    /** @return array{rows:array<int,array>, categories:array<string,array>, total_value:int, gl_value:int, difference:int} */
    public function asOf(?string $date = null, ?int $categoryId = null, bool $hideZero = true): array
    {
        $last = InventoryTransaction::whereIn('id', InventoryTransaction::query()->selectRaw('MAX(id)')
            ->when($date, fn ($q) => $q->where('txn_date', '<=', $date))->groupBy('product_id'))->get()->keyBy('product_id');

        $products = Product::withTrashed()->with(['category', 'unit'])->when($categoryId, fn ($q) => $q->where('category_id', $categoryId))->orderBy('name')->get();

        $rows = [];
        $categories = [];
        $total = 0;
        foreach ($products as $p) {
            $row = $last->get($p->id);
            $qty = $row ? Qty::toMilli($row->balance_qty) : 0;
            $value = $row ? Money::toCents($row->balance_value) : 0;
            if ($hideZero && $qty === 0 && $value === 0) {
                continue;
            }
            $cat = $p->category?->name ?? 'Uncategorised';
            $rows[] = ['code' => $p->code, 'name' => $p->name, 'category' => $cat, 'unit' => $p->unit?->short_name ?? $p->unit?->name, 'qty' => $qty,
                       'avg' => $row ? $row->avg_cost_after : '0.0000', 'value' => $value];
            $categories[$cat]['value'] = ($categories[$cat]['value'] ?? 0) + $value;
            $categories[$cat]['qty_lines'] = ($categories[$cat]['qty_lines'] ?? 0) + 1;
            $total += $value;
        }
        ksort($categories);

        // control check: the whole ledger (all products) against the Inventory account on the same date
        $gl = JournalEntryLine::query()->join('accounts as a', 'a.id', '=', 'journal_entry_lines.account_id')
            ->join('journal_entries as e', 'e.id', '=', 'journal_entry_lines.journal_entry_id')->where('a.code', AccountCodes::INVENTORY)
            ->when($date, fn ($q) => $q->where('e.entry_date', '<=', $date))
            ->selectRaw('SUM(journal_entry_lines.debit) as d, SUM(journal_entry_lines.credit) as c')->first();
        $glValue = Money::toCents($gl->d) - Money::toCents($gl->c);
        $ledgerAll = $last->sum(fn ($r) => Money::toCents($r->balance_value));

        return ['rows' => $rows, 'categories' => $categories, 'total_value' => $total, 'gl_value' => $glValue, 'difference' => $ledgerAll - $glValue];
    }
}
