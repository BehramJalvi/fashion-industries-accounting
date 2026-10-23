<?php

namespace App\Services\Reports;

use App\Models\Expense;
use App\Models\FundTransfer;
use App\Models\InventoryTransaction;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\OwnerTransaction;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Receipt;
use App\Models\Sale;
use App\Models\SalesReturn;
use App\Models\StockAdjustment;
use App\Support\Money;
use App\Support\Qty;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Figures for the dashboard. Period figures (sales, purchases, profit) use the chosen period; balances (receivables, payables, cash,
 * bank, inventory) are as at today. Everything comes from the journal / stock ledger, the same sources as the reports.
 * Money in paisa.
 */
class DashboardService
{
    public function __construct(private IncomeStatementService $income, private AgingService $aging)
    {
    }

    /** @return array{0:string,1:string,2:string} from, to, label */
    public function period(string $period, ?string $from, ?string $to): array
    {
        $today = Carbon::today();

        return match ($period) {
            'fy'     => [\App\Models\FiscalYear::forDate($today->toDateString())?->start_date?->toDateString() ?? $today->copy()->startOfYear()->toDateString(), $today->toDateString(), 'This fiscal year'],
            'last30' => [$today->copy()->subDays(29)->toDateString(), $today->toDateString(), 'Last 30 days'],
            'custom' => [$from ?: $today->copy()->startOfMonth()->toDateString(), $to ?: $today->toDateString(), 'Custom period'],
            default  => [$today->copy()->startOfMonth()->toDateString(), $today->toDateString(), 'This month'],
        };
    }

    public function build(string $from, string $to): array
    {
        $today = Carbon::today();
        $pl = $this->income->build($from, $to);

        $sum = fn ($rows, string $sub) => $rows->where('sub_type', $sub)->sum('net');
        $rows = $this->balances($today->toDateString());

        $cashAccounts = $rows->filter(fn ($r) => in_array($r['sub_type'], ['cash', 'bank'], true))->map(fn ($r) => ['name' => $r['name'], 'balance' => $r['net'], 'kind' => $r['sub_type']])->values();
        $receivable = $sum($rows, 'receivable');
        $overdue = $this->aging->receivables($today)['totals'];
        $suppliers = -$sum($rows, 'payable');
        $employees = -$sum($rows, 'employee_payable') - $sum($rows, 'advance');
        $inventory = $sum($rows, 'inventory');
        $stockLedger = $this->stockLedgerValue();

        return [
            'sales' => ['net' => $pl['net_sales'], 'gross' => $pl['gross_sales'], 'count' => Sale::where('status', 'posted')->whereDate('sale_date', '>=', $from)->whereDate('sale_date', '<=', $to)->count()],
            'purchases' => $this->purchases($from, $to),
            'profit' => ['gross' => $pl['gross_profit'], 'net' => $pl['net_profit'], 'margin' => $pl['margin'], 'expenses' => $pl['total_expenses']],
            'receivables' => ['total' => $receivable, 'overdue' => $overdue['d30'] + $overdue['d60'] + $overdue['d90'] + $overdue['d90p']],
            'payables' => ['suppliers' => $suppliers, 'employees' => $employees, 'total' => $suppliers + $employees],
            'cash' => ['cash' => $rows->where('sub_type', 'cash')->sum('net'), 'bank' => $rows->where('sub_type', 'bank')->sum('net'), 'accounts' => $cashAccounts],
            'inventory' => ['value' => $inventory, 'difference' => $stockLedger - $inventory],
            'tax' => ['payable' => -$sum($rows, 'tax_payable') - $sum($rows, 'tax')],
            'low_stock' => $this->lowStock(),
            'recent' => $this->recent(),
            'trend' => $this->trend($today),
            'attention' => $this->attention($rows),
        ];
    }

    /** What a user without the reports permission may see: stock warnings, recent documents and what is waiting. No money totals. */
    public function buildOperational(): array
    {
        return ['low_stock' => $this->lowStock(), 'recent' => $this->recent(), 'attention' => ['drafts' => $this->attention(collect())['drafts'], 'journal_difference' => 0],
                'inventory' => ['value' => 0, 'difference' => 0]];
    }

    /** @return Collection<int, array{code:string,name:string,type:string,sub_type:?string,net:int}> debit minus credit per account up to a date */
    private function balances(string $date): Collection
    {
        return JournalEntryLine::query()
            ->join('journal_entries as e', 'e.id', '=', 'journal_entry_lines.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'journal_entry_lines.account_id')->whereDate('e.entry_date', '<=', $date)
            ->selectRaw('a.code, a.name, a.type, a.sub_type, SUM(journal_entry_lines.debit) as d, SUM(journal_entry_lines.credit) as c')
            ->groupBy('a.code', 'a.name', 'a.type', 'a.sub_type')->orderBy('a.code')->get()
            ->map(fn ($r) => ['code' => $r->code, 'name' => $r->name, 'type' => $r->type, 'sub_type' => $r->sub_type, 'net' => Money::toCents($r->d) - Money::toCents($r->c)]);
    }

    /** Purchases net of discount and tax, less purchase returns. */
    private function purchases(string $from, string $to): array
    {
        $bought = Purchase::where('status', 'posted')->whereBetween('purchase_date', [$from, $to]);
        $net = Money::toCents($bought->sum('subtotal')) - Money::toCents((clone $bought)->sum('discount_total'));
        $returned = Money::toCents(PurchaseReturn::where('status', 'posted')->whereBetween('return_date', [$from, $to])->sum('subtotal'));

        return ['net' => $net - $returned, 'bought' => $net, 'returned' => $returned, 'count' => (clone $bought)->count()];
    }

    private function stockLedgerValue(): int
    {
        return InventoryTransaction::whereIn('id', InventoryTransaction::query()->selectRaw('MAX(id)')->groupBy('product_id'))->get()
            ->sum(fn ($r) => Money::toCents($r->balance_value));
    }

    /** Active products below their minimum stock, most short first. @return array{count:int, items:array<int,array>} */
    private function lowStock(): array
    {
        $products = Product::where('is_active', true)->where('min_stock', '>', 0)->with('unit')->get();
        if ($products->isEmpty()) {
            return ['count' => 0, 'items' => []];
        }

        $last = InventoryTransaction::whereIn('id', InventoryTransaction::query()->selectRaw('MAX(id)')->groupBy('product_id'))->get()->keyBy('product_id');
        $low = $products->map(function (Product $p) use ($last) {
            $qty = Qty::toMilli($last->get($p->id)?->balance_qty ?? 0);
            $min = Qty::toMilli($p->min_stock);

            return ['id' => $p->id, 'name' => $p->name, 'code' => $p->code, 'unit' => $p->unit?->short_name ?? $p->unit?->name, 'qty' => $qty, 'min' => $min, 'ratio' => $min > 0 ? $qty / $min : 0];
        })->filter(fn ($r) => $r['qty'] < $r['min'])->sortBy('ratio')->values();

        return ['count' => $low->count(), 'items' => $low->take(8)->all()];
    }

    private function recent(): array
    {
        return [
            'sales' => Sale::with('customer')->where('status', 'posted')->latest('sale_date')->latest('id')->limit(5)->get(),
            'purchases' => Purchase::with('supplier')->where('status', 'posted')->latest('purchase_date')->latest('id')->limit(5)->get(),
            'cash' => JournalEntry::whereIn('voucher_type', ['receipt', 'payment', 'expense', 'transfer', 'owner'])->where('status', 'posted')->latest('entry_date')->latest('id')->limit(6)->get(),
        ];
    }

    /** Net sales and net profit for each of the last six months. */
    private function trend(Carbon $today): array
    {
        $months = [];
        for ($i = 5; $i >= 0; $i--) {
            $start = $today->copy()->startOfMonth()->subMonths($i);
            $pl = $this->income->build($start->toDateString(), $start->copy()->endOfMonth()->toDateString());
            $months[] = ['label' => $start->format('M Y'), 'sales' => $pl['net_sales'], 'profit' => $pl['net_profit']];
        }
        $max = max(1, ...array_map(fn ($m) => abs($m['sales']), $months));

        return ['months' => $months, 'max' => $max];
    }

    /** Things that need a person: drafts waiting to be posted, and any control-total that does not agree. */
    private function attention(Collection $rows): array
    {
        $drafts = [];
        foreach ([
            ['Sales invoices', Sale::class, 'sales.index', 'sales.view'], ['Purchases', Purchase::class, 'purchases.index', 'purchases.view'],
            ['Sales returns', SalesReturn::class, 'sales-returns.index', 'sales_returns.view'], ['Purchase returns', PurchaseReturn::class, 'purchase-returns.index', 'purchase_returns.view'],
            ['Receipts', Receipt::class, 'receipts.index', 'receipts.view'], ['Payments', Payment::class, 'payments.index', 'payments.view'], ['Expenses', Expense::class, 'expenses.index', 'expenses.view'],
            ['Fund transfers', FundTransfer::class, 'fund-transfers.index', 'fund_transfers.view'], ['Owner capital / drawings', OwnerTransaction::class, 'owner-transactions.index', 'owner_transactions.view'],
            ['Stock adjustments', StockAdjustment::class, 'stock-adjustments.index', 'stock_adjustments.view'],
        ] as [$label, $class, $route, $permission]) {
            $count = $class::where('status', 'draft')->count();
            if ($count > 0) {
                $drafts[] = ['label' => $label, 'count' => $count, 'route' => $route, 'permission' => $permission];
            }
        }

        $debits = Money::toCents(JournalEntryLine::sum('debit'));
        $credits = Money::toCents(JournalEntryLine::sum('credit'));

        return ['drafts' => $drafts, 'journal_difference' => $debits - $credits];
    }
}
