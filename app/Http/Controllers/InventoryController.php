<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\InventoryTransaction;
use App\Models\JournalEntryLine;
use App\Models\Product;
use App\Support\AccountCodes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class InventoryController extends Controller
{
    /** Stock summary: quantity, average cost and value per product, reconciled to the Inventory account. */
    public function index(Request $request): View
    {
        Gate::authorize('inventory.view');

        $last = InventoryTransaction::whereIn('id', InventoryTransaction::query()->selectRaw('MAX(id)')->groupBy('product_id'))
            ->get()->keyBy('product_id');

        $rows = Product::with(['category', 'unit'])->where('is_active', true)
            ->when($request->q, fn ($q, $t) => $q->where(fn ($w) => $w->where('name', 'like', "%$t%")->orWhere('code', 'like', "%$t%")))
            ->when($request->category_id, fn ($q, $v) => $q->where('category_id', $v))
            ->orderBy('name')->get()
            ->map(function (Product $p) use ($last) {
                $row = $last->get($p->id);
                $p->setAttribute('qty', (float) ($row->balance_qty ?? 0));
                $p->setAttribute('avg', (float) ($row->avg_cost_after ?? 0));
                $p->setAttribute('value', (float) ($row->balance_value ?? 0));
                $p->setAttribute('low', $p->qty < (float) $p->min_stock);

                return $p;
            })
            ->when($request->boolean('low'), fn ($c) => $c->filter(fn ($p) => $p->low));

        // Control check: stock ledger value (all products) must equal the Inventory account balance.
        $ledgerValue = $last->sum(fn ($r) => (float) $r->balance_value);
        $glValue = (float) JournalEntryLine::query()
            ->join('accounts as a', 'a.id', '=', 'journal_entry_lines.account_id')->where('a.code', AccountCodes::INVENTORY)
            ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) as bal')->value('bal');

        return view('inventory.index', [
            'rows' => $rows, 'categories' => Category::orderBy('name')->get(),
            'totalValue' => $rows->sum('value'), 'ledgerValue' => $ledgerValue, 'glValue' => $glValue,
            'reconciled' => abs($ledgerValue - $glValue) < 0.005,
        ]);
    }

    /** Inventory ledger for one product: opening, movements by type, closing (the workbook's Inventory sheet, per item). */
    public function ledger(Request $request): View
    {
        Gate::authorize('inventory.view');
        $request->validate(['product_id' => 'nullable|integer|exists:products,id', 'from' => 'nullable|date', 'to' => 'nullable|date']);

        $product = $request->product_id ? Product::withTrashed()->find($request->product_id) : null;
        $data = ['products' => Product::orderBy('name')->get(['id', 'code', 'name']), 'product' => $product];

        if ($product) {
            $base = InventoryTransaction::where('product_id', $product->id);
            $from = $request->from;
            $to = $request->to;

            $opening = $from ? (clone $base)->whereDate('txn_date', '<', $from)->orderByDesc('id')->first() : null;
            $rows = (clone $base)
                ->when($from, fn ($q, $v) => $q->whereDate('txn_date', '>=', $v))
                ->when($to, fn ($q, $v) => $q->whereDate('txn_date', '<=', $v))
                ->orderBy('txn_date')->orderBy('id')->get();

            $data += [
                'rows' => $rows,
                'openingQty' => (float) ($opening->balance_qty ?? 0), 'openingValue' => (float) ($opening->balance_value ?? 0),
                'closing' => $rows->last() ?? $opening,
                'byType' => $rows->groupBy('txn_type')->map(fn ($g) => [
                    'in' => (float) $g->sum('qty_in'), 'out' => (float) $g->sum('qty_out'), 'cost' => (float) $g->sum('total_cost'),
                ]),
            ];
        }

        return view('inventory.ledger', $data);
    }
}
