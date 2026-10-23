<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Category;
use App\Models\Employee;
use App\Models\Owner;
use App\Models\Supplier;
use App\Services\Accounting\PartyLedger;
use App\Services\Reports\AgingService;
use App\Services\Reports\BalanceSheetService;
use App\Services\Reports\CashFlowService;
use App\Services\Reports\DayBookService;
use App\Services\Reports\DocumentReportService;
use App\Services\Reports\IncomeStatementService;
use App\Services\Reports\ReportPeriod;
use App\Services\Reports\StockValuationService;
use App\Services\Reports\TrialBalanceService;
use App\Models\JournalEntry;
use App\Support\Csv;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ReportController extends Controller
{
    public function index(): View
    {
        Gate::authorize('reports.view');

        return view('reports.index', ['documentTypes' => DocumentReportService::types()]);
    }

    public function trialBalance(Request $request, TrialBalanceService $service): mixed
    {
        [$from, $to] = $this->period($request);
        $tb = $service->build($from, $to);

        if ($this->csv($request)) {
            $rows = array_map(fn ($r) => [$r['code'], $r['name'], $r['type'], ...array_map(fn ($k) => Money::fromCents($r[$k]), ['open_dr', 'open_cr', 'period_dr', 'period_cr', 'close_dr', 'close_cr'])], $tb['rows']);
            $rows[] = ['', 'TOTAL', '', ...array_map(fn ($k) => Money::fromCents($tb['totals'][$k]), ['open_dr', 'open_cr', 'period_dr', 'period_cr', 'close_dr', 'close_cr'])];

            return Csv::download("trial-balance-{$to}.csv", ['Code', 'Account', 'Type', 'Opening Dr', 'Opening Cr', 'Period Dr', 'Period Cr', 'Closing Dr', 'Closing Cr'], $rows);
        }

        return view('reports.trial-balance', compact('tb', 'from', 'to'));
    }

    public function profitLoss(Request $request, IncomeStatementService $service): mixed
    {
        [$from, $to] = $this->period($request);
        $pl = $service->build($from, $to);

        if ($this->csv($request)) {
            $rows = [];
            $section = function (string $title, array $lines, ?int $total = null, string $totalLabel = '') use (&$rows) {
                $rows[] = [$title, '', ''];
                foreach ($lines as $l) {
                    $rows[] = ['', $l['code'] . ' ' . $l['name'], Money::fromCents($l['amount'])];
                }
                if ($total !== null) {
                    $rows[] = [$totalLabel, '', Money::fromCents($total)];
                }
            };
            $section('Sales', $pl['sales'], $pl['gross_sales'], 'Gross sales');
            $section('Less: returns and discounts', $pl['deductions'], $pl['total_deductions'], 'Total deductions');
            $section('Other income', $pl['other_income'], $pl['other'], 'Total other income');
            $rows[] = ['NET SALES', '', Money::fromCents($pl['net_sales'])];
            $section('Cost of goods sold', $pl['cogs'], $pl['total_cogs'], 'Total cost of goods sold');
            $rows[] = ['GROSS PROFIT', '', Money::fromCents($pl['gross_profit'])];
            $section('Operating expenses', $pl['expenses'], $pl['total_expenses'], 'Total operating expenses');
            $rows[] = ['NET PROFIT / (LOSS)', '', Money::fromCents($pl['net_profit'])];

            return Csv::download("profit-and-loss-{$from}-to-{$to}.csv", ['Section', 'Account', 'Amount'], $rows);
        }

        return view('reports.profit-loss', compact('pl', 'from', 'to'));
    }

    public function balanceSheet(Request $request, BalanceSheetService $service): mixed
    {
        Gate::authorize('reports.view');
        $request->validate(['to' => 'nullable|date']);
        $to = $request->to ?: Carbon::today()->toDateString();
        $bs = $service->asAt($to);

        if ($this->csv($request)) {
            $rows = [];
            foreach (['assets' => 'Assets', 'liabilities' => 'Liabilities', 'equity' => 'Equity'] as $key => $title) {
                foreach ($bs[$key] as $l) {
                    $rows[] = [$title, $l['code'] . ' ' . $l['name'], Money::fromCents($l['amount'])];
                }
            }
            $rows[] = ['Equity', 'Profit / (loss) not yet closed to Retained Earnings', Money::fromCents($bs['profit'])];
            $rows[] = ['TOTAL', 'Assets', Money::fromCents($bs['total_assets'])];
            $rows[] = ['TOTAL', 'Liabilities', Money::fromCents($bs['total_liabilities'])];
            $rows[] = ['TOTAL', 'Equity', Money::fromCents($bs['total_equity'])];

            return Csv::download("balance-sheet-{$to}.csv", ['Section', 'Account', 'Amount'], $rows);
        }

        return view('reports.balance-sheet', compact('bs', 'to'));
    }

    public function receivables(Request $request, AgingService $service): mixed
    {
        return $this->aging($request, 'Outstanding receivables', [['title' => 'Customers', 'data' => fn ($d) => $service->receivables($d), 'ledger' => 'customers.ledger']]);
    }

    public function payables(Request $request, AgingService $service): mixed
    {
        return $this->aging($request, 'Outstanding payables', [
            ['title' => 'Suppliers', 'data' => fn ($d) => $service->payables($d), 'ledger' => 'suppliers.ledger'],
            ['title' => 'Employees (salary and money they spent for the business)', 'data' => fn ($d) => $service->employees($d), 'ledger' => 'employees.ledger'],
        ]);
    }

    public function stockValuation(Request $request, StockValuationService $service): mixed
    {
        Gate::authorize('reports.view');
        $request->validate(['as_of' => 'nullable|date', 'category_id' => 'nullable|integer']);
        $v = $service->asOf($request->as_of, $request->category_id ? (int) $request->category_id : null, ! $request->boolean('show_zero'));

        if ($this->csv($request)) {
            $rows = array_map(fn ($r) => [$r['code'], $r['name'], $r['category'], $r['unit'], Money::fromScaled($r['qty'], 3), $r['avg'], Money::fromCents($r['value'])], $v['rows']);
            $rows[] = ['', 'TOTAL', '', '', '', '', Money::fromCents($v['total_value'])];

            return Csv::download('stock-valuation-' . ($request->as_of ?: Carbon::today()->toDateString()) . '.csv', ['Code', 'Product', 'Category', 'Unit', 'Quantity', 'Average cost', 'Value'], $rows);
        }

        return view('reports.stock-valuation', ['v' => $v, 'asOf' => $request->as_of, 'categoryId' => $request->category_id, 'categories' => Category::orderBy('name')->get(), 'showZero' => $request->boolean('show_zero')]);
    }

    public function cashFlow(Request $request, CashFlowService $service): mixed
    {
        [$from, $to] = $this->period($request);
        $cf = $service->build($from, $to);

        if ($this->csv($request)) {
            $rows = [['Opening cash and bank', '', '', Money::fromCents($cf['opening'])]];
            foreach ($cf['rows'] as $r) {
                $rows[] = [$r['label'], Money::fromCents($r['in']), Money::fromCents($r['out']), Money::fromCents($r['net'])];
            }
            $rows[] = ['Closing cash and bank', Money::fromCents($cf['in']), Money::fromCents($cf['out']), Money::fromCents($cf['closing'])];

            return Csv::download("cash-flow-{$from}-to-{$to}.csv", ['Item', 'Money in', 'Money out', 'Net / balance'], $rows);
        }

        return view('reports.cash-flow', compact('cf', 'from', 'to'));
    }

    public function dayBook(Request $request, DayBookService $service): mixed
    {
        Gate::authorize('reports.view');
        $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from', 'voucher_type' => 'nullable|string|max:30']);
        $from = $request->from ?: Carbon::today()->toDateString();
        $to = $request->to ?: $from;
        $book = $service->build($from, $to, $request->voucher_type);

        if ($this->csv($request)) {
            $rows = $book['entries']->map(fn ($e) => [$e->entry_date->format('Y-m-d'), $e->entry_no, $e->voucher_type, $e->reference, $e->description, $e->status, $e->total_amount])->all();

            return Csv::download("day-book-{$from}-to-{$to}.csv", ['Date', 'Entry', 'Type', 'Reference', 'Description', 'Status', 'Amount'], $rows);
        }

        return view('reports.day-book', ['book' => $book, 'from' => $from, 'to' => $to, 'voucher' => $request->voucher_type,
            'voucherTypes' => JournalEntry::query()->select('voucher_type')->distinct()->orderBy('voucher_type')->pluck('voucher_type')]);
    }

    /** Balances of every customer / supplier / employee / owner as at a date. */
    public function balances(Request $request, string $type, PartyLedger $ledger): mixed
    {
        Gate::authorize('reports.view');
        $request->validate(['to' => 'nullable|date']);
        $to = $request->to ?: Carbon::today()->toDateString();

        [$class, $alias, $title, $routeLedger, $positive, $negative] = match ($type) {
            'customers' => [Customer::class, 'customer', 'Customer balances', 'customers.ledger', 'Owes us', 'Advance received'],
            'suppliers' => [Supplier::class, 'supplier', 'Supplier balances', 'suppliers.ledger', 'We owe', 'Advance paid'],
            'employees' => [Employee::class, 'employee', 'Employee balances', 'employees.ledger', 'We owe', 'Owes us'],
            'owners'    => [Owner::class, 'owner', 'Owner capital balances', 'owners.ledger', 'Capital', 'Overdrawn'],
        };
        $proto = new $class();
        $balances = $ledger->balances($alias, $proto->ledgerControlCodes(), $proto->ledgerNature(), $to);
        $names = $class::withTrashed()->whereIn('id', array_keys($balances))->pluck('name', 'id');
        $rows = collect($balances)->filter(fn ($b) => $b !== 0)->map(fn ($b, $id) => ['id' => $id, 'name' => $names[$id] ?? "#{$id}", 'balance' => $b])->sortBy('name')->values()->all();

        if ($this->csv($request)) {
            return Csv::download("{$type}-balances-{$to}.csv", ['Name', 'Balance', 'Meaning'], array_map(fn ($r) => [$r['name'], Money::fromCents($r['balance']), $r['balance'] >= 0 ? $positive : $negative], $rows));
        }

        return view('reports.balances', compact('rows', 'to', 'type', 'title', 'routeLedger', 'positive', 'negative') + ['total' => array_sum(array_column($rows, 'balance'))]);
    }

    /** Listing reports (sales, purchases, returns, expenses, receipts, payments) with date range, status and party filters. */
    public function documents(Request $request, string $type, DocumentReportService $service): mixed
    {
        Gate::authorize('reports.view');
        abort_unless(array_key_exists($type, DocumentReportService::types()), 404);
        $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from', 'status' => 'nullable|in:posted,cancelled,draft,all', 'party_id' => 'nullable|integer']);

        [$from, $to] = ReportPeriod::resolve($request->from, $request->to);
        $report = $service->build($type, $from, $to, $request->status ?: 'posted', $request->party_id ? (int) $request->party_id : null);

        if ($this->csv($request)) {
            $rows = array_map(fn ($r) => array_map(fn ($c) => is_array($c) ? Money::fromCents($c['m']) : $c, $r), $report['rows']);
            $rows[] = array_map(fn ($i) => $report['headers'][$i][1] ? Money::fromCents($report['totals'][$i]) : ($i === 0 ? 'TOTAL' : ''), array_keys($report['headers']));

            return Csv::download("{$type}-{$from}-to-{$to}.csv", array_column($report['headers'], 0), $rows);
        }

        return view('reports.documents', ['report' => $report, 'type' => $type, 'from' => $from, 'to' => $to, 'status' => $request->status ?: 'posted',
            'partyId' => $request->party_id, 'party' => $service->partyFilter($type), 'types' => DocumentReportService::types()]);
    }

    // ------------------------------------------------------------------ helpers

    private function period(Request $request): array
    {
        Gate::authorize('reports.view');
        $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from']);

        return ReportPeriod::resolve($request->from, $request->to);
    }

    private function csv(Request $request): bool
    {
        if ($request->query('export') !== 'csv') {
            return false;
        }
        Gate::authorize('reports.export');

        return true;
    }

    /** @param array<int, array{title:string, data:\Closure, ledger:string}> $sections */
    private function aging(Request $request, string $title, array $sections): mixed
    {
        Gate::authorize('reports.view');
        $request->validate(['as_of' => 'nullable|date']);
        $asOf = $request->as_of ? Carbon::parse($request->as_of) : Carbon::today();

        $built = array_map(fn ($s) => ['title' => $s['title'], 'ledger' => $s['ledger'], 'data' => ($s['data'])($asOf)], $sections);

        if ($this->csv($request)) {
            $rows = [];
            foreach ($built as $s) {
                foreach ($s['data']['rows'] as $r) {
                    $rows[] = [$s['title'], $r['name'], ...array_map(fn ($k) => Money::fromCents($r[$k]), ['current', 'd30', 'd60', 'd90', 'd90p', 'other', 'total'])];
                }
                $rows[] = [$s['title'], 'TOTAL', ...array_map(fn ($k) => Money::fromCents($s['data']['totals'][$k]), ['current', 'd30', 'd60', 'd90', 'd90p', 'other', 'total'])];
            }

            return Csv::download(str($title)->slug() . '-' . $asOf->toDateString() . '.csv', ['Section', 'Name', ...array_values(AgingService::BUCKETS), 'Opening / on account', 'Total'], $rows);
        }

        return view('reports.aging', ['title' => $title, 'sections' => $built, 'asOf' => $asOf->toDateString(), 'buckets' => AgingService::BUCKETS]);
    }
}
