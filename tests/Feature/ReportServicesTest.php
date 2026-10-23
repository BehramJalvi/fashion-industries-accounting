<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Services\Accounting\PartyLedger;
use App\Services\Reports\AgingService;
use App\Services\Reports\BalanceSheetService;
use App\Services\Reports\CashFlowService;
use App\Services\Reports\DayBookService;
use App\Services\Reports\DocumentReportService;
use App\Services\Reports\IncomeStatementService;
use App\Services\Reports\LedgerService;
use App\Services\Reports\StockValuationService;
use App\Services\Reports\TrialBalanceService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BooksScenario;
use Tests\TestCase;

/** Every report is checked against figures worked out by hand in tests/Concerns/BooksScenario.php (all money in paisa here). */
class ReportServicesTest extends TestCase
{
    use BooksScenario, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildScenario();
    }

    public function test_trial_balance_balances_and_matches_the_hand_calculation(): void
    {
        $tb = app(TrialBalanceService::class)->build('2026-10-01', '2026-10-31');
        $row = collect($tb['rows'])->keyBy('code');

        $this->assertTrue($tb['balanced']);
        $this->assertSame($tb['totals']['close_dr'], $tb['totals']['close_cr']);
        $this->assertSame($tb['totals']['period_dr'], $tb['totals']['period_cr']);
        $this->assertSame(45000000, $row['1010']['close_dr']);     // bank 450,000
        $this->assertSame(2500000, $row['1000']['close_dr']);      // cash 25,000
        $this->assertSame(9470000, $row['1100']['close_dr']);      // receivable 94,700
        $this->assertSame(3800000, $row['1200']['close_dr']);      // inventory 38,000
        $this->assertSame(4000000, $row['2000']['close_cr']);      // payable 40,000
        $this->assertSame(4000000, $row['2100']['close_cr']);      // Mohsin 40,000
        $this->assertSame(2970000, $row['2400']['close_cr']);      // sales tax 29,700
        $this->assertSame(50000000, $row['3000']['close_cr']);     // capital 500,000
        $this->assertSame(11000000, $row['5000']['close_dr']);     // cost of goods sold 120,000 - 10,000
    }

    public function test_trial_balance_opening_and_movement_split_at_the_from_date(): void
    {
        $row = collect(app(TrialBalanceService::class)->build('2026-10-10', '2026-10-31')['rows'])->keyBy('code');

        // bank before 10-Oct: capital 500,000 less the cash purchase 50,000
        $this->assertSame(45000000, $row['1010']['open_dr']);
        $this->assertSame(10000000, $row['1010']['period_dr']);    // receipt 100,000
        $this->assertSame(10000000, $row['1010']['period_cr']);    // payment 60,000 + transfer 40,000
        $this->assertSame(45000000, $row['1010']['close_dr']);

        $early = app(TrialBalanceService::class)->build('2026-10-01', '2026-10-04');          // only the capital and the two purchases exist yet
        $this->assertTrue($early['balanced']);
        $this->assertNull(collect($early['rows'])->firstWhere('code', '4000'));
    }

    public function test_profit_and_loss_for_the_month(): void
    {
        $pl = app(IncomeStatementService::class)->build('2026-10-01', '2026-10-31');

        $this->assertSame(18000000, $pl['gross_sales']);
        $this->assertSame(1500000, $pl['total_deductions']);        // sales return 15,000
        $this->assertSame(16500000, $pl['net_sales']);
        $this->assertSame(11200000, $pl['total_cogs']);             // 110,000 cost of goods + 2,000 stock loss
        $this->assertSame(5300000, $pl['gross_profit']);
        $this->assertSame(5500000, $pl['total_expenses']);          // rent 15,000 + salary 40,000
        $this->assertSame(-200000, $pl['net_profit']);              // a loss of 2,000
        $this->assertSame(32.1, $pl['margin']);
        $this->assertEqualsCanonicalizing(['Salaries & Wages', 'Rent'], collect($pl['expenses'])->pluck('name')->all());
    }

    public function test_profit_and_loss_respects_the_period(): void
    {
        $early = app(IncomeStatementService::class)->build('2026-10-01', '2026-10-09');      // only the sale (and its cost) so far

        $this->assertSame(18000000, $early['net_sales']);
        $this->assertSame(12000000, $early['total_cogs']);
        $this->assertSame(0, $early['total_expenses']);
        $this->assertSame(6000000, $early['net_profit']);
    }

    public function test_balance_sheet_balances_and_matches_the_hand_calculation(): void
    {
        $bs = app(BalanceSheetService::class)->asAt('2026-10-31');

        $this->assertTrue($bs['balanced']);
        $this->assertSame(0, $bs['difference']);
        $this->assertSame(60770000, $bs['total_assets']);           // 25,000 + 450,000 + 94,700 + 38,000
        $this->assertSame(10970000, $bs['total_liabilities']);      // 40,000 + 40,000 + 29,700
        $this->assertSame(-200000, $bs['profit']);
        $this->assertSame(49800000, $bs['total_equity']);           // 500,000 - 2,000
        $this->assertSame($bs['total_assets'], $bs['total_liabilities'] + $bs['total_equity']);
    }

    public function test_balance_sheet_at_an_earlier_date_still_balances(): void
    {
        $bs = app(BalanceSheetService::class)->asAt('2026-10-09');

        $this->assertTrue($bs['balanced']);
        $this->assertSame(69240000, $bs['total_assets']);           // bank 450,000 + receivable 212,400 + inventory 30,000
        $this->assertSame(13240000, $bs['total_liabilities']);      // payable 100,000 + sales tax 32,400
        $this->assertSame(56000000, $bs['total_equity']);           // capital 500,000 + profit so far 60,000
    }

    public function test_cash_flow_groups_money_by_kind_and_reconciles(): void
    {
        $cf = app(CashFlowService::class)->build('2026-10-01', '2026-10-31');
        $net = collect($cf['rows'])->pluck('net', 'type');

        $this->assertSame(0, $cf['opening']);
        $this->assertSame(47500000, $cf['closing']);                // cash 25,000 + bank 450,000
        $this->assertSame(50000000, $net['owner']);
        $this->assertSame(-5000000, $net['purchase']);              // the walk-in purchase paid from bank
        $this->assertSame(10000000, $net['receipt']);
        $this->assertSame(-6000000, $net['payment']);
        $this->assertSame(-1500000, $net['expense']);
        $this->assertSame(0, $net['transfer']);                     // between our own accounts
        $this->assertSame($cf['closing'], $cf['opening'] + $cf['in'] - $cf['out']);
        $this->assertSame(47500000, array_sum(array_column($cf['accounts'], 'closing')));

        $later = app(CashFlowService::class)->build('2026-10-10', '2026-10-31');
        $this->assertSame(45000000, $later['opening']);              // cash and bank before 10-Oct: 500,000 capital less the 50,000 purchase
    }

    public function test_receivables_are_aged_by_days_past_the_due_date_and_total_the_control_account(): void
    {
        $service = app(AgingService::class);

        $at = fn (string $date) => $service->receivables(Carbon::parse($date));
        $row = fn (array $report) => $report['rows'][0];

        // invoice dated 05-Oct with the customer's 30 days: due 04-Nov. Balance 212,400 - 100,000 receipt - 17,700 return = 94,700
        $this->assertSame(9470000, $row($at('2026-10-20'))['current']);
        $this->assertSame(9470000, $row($at('2026-11-20'))['d30']);
        $this->assertSame(9470000, $row($at('2027-03-01'))['d90p']);
        $this->assertSame(9470000, $row($at('2026-10-20'))['total']);
        $this->assertSame(0, $row($at('2026-10-20'))['other']);                     // everything is on an open invoice
        $this->assertSame('Multan Fabrics PVT Limited', $row($at('2026-10-20'))['name']);
        $this->assertSame(9470000, $at('2026-10-20')['totals']['total']);
    }

    public function test_payables_split_suppliers_and_employees(): void
    {
        $service = app(AgingService::class);

        $suppliers = $service->payables(Carbon::parse('2026-10-20'));
        $this->assertCount(1, $suppliers['rows']);                                  // the walk-in purchase has no supplier
        $this->assertSame('Al Zamin', $suppliers['rows'][0]['name']);
        $this->assertSame(4000000, $suppliers['rows'][0]['current']);               // 100,000 - 60,000, due 01-Nov
        $this->assertSame(4000000, $suppliers['totals']['total']);

        $employees = $service->employees(Carbon::parse('2026-10-20'));
        $this->assertSame('Mohsin', $employees['rows'][0]['name']);
        $this->assertSame(0, $employees['rows'][0]['open']);                        // a salary is not a bill
        $this->assertSame(4000000, $employees['rows'][0]['other']);
        $this->assertSame(4000000, $employees['rows'][0]['total']);
    }

    public function test_stock_valuation_matches_the_inventory_account_now_and_in_the_past(): void
    {
        $now = app(StockValuationService::class)->asOf();

        $this->assertSame(3800000, $now['total_value']);
        $this->assertSame(0, $now['difference']);
        $this->assertSame(38000, $now['rows'][0]['qty']);                           // 38 units in thousandths
        $this->assertSame('1000.0000', $now['rows'][0]['avg']);

        $before = app(StockValuationService::class)->asOf('2026-10-04');            // before the sale
        $this->assertSame(15000000, $before['total_value']);
        $this->assertSame(0, $before['difference']);
    }

    public function test_the_bank_book_and_filters(): void
    {
        $ledger = app(LedgerService::class);
        $bank = $ledger->presetAccountIds('bank');

        $book = $ledger->statement($bank, '2026-10-01', '2026-10-31');
        $this->assertTrue($book['running']);
        $this->assertSame(45000000, $book['closing']);
        $this->assertSame(0, $book['opening']);
        $this->assertSame(60000000, $book['debit']);                                   // capital 500,000 + receipt 100,000
        $this->assertSame(15000000, $book['credit']);                                  // 50,000 + 60,000 + 40,000

        // transaction-type filter: only receipts, no running balance
        $receipts = $ledger->statement($bank, '2026-10-01', '2026-10-31', 'receipt');
        $this->assertFalse($receipts['running']);
        $this->assertNull($receipts['closing']);
        $this->assertSame(10000000, $receipts['debit']);
        $this->assertCount(1, $receipts['rows']);
    }

    public function test_the_receivable_ledger_filtered_to_one_customer(): void
    {
        $ledger = app(LedgerService::class);
        $ar = [Account::byCode('1100')->id];

        $all = $ledger->statement($ar, '2026-10-01', '2026-10-31', null, 'customer', $this->customer->id);
        $this->assertSame(9470000, $all['closing']);
        $this->assertSame('Customer: Multan Fabrics PVT Limited', $all['rows'][0]['party']);

        $from = $ledger->statement($ar, '2026-10-10', '2026-10-31', null, 'customer', $this->customer->id);
        $this->assertSame(21240000, $from['opening']);                              // the invoice, before the receipt and the return
        $this->assertSame(9470000, $from['closing']);

        $nobody = $ledger->statement($ar, '2026-10-01', '2026-10-31', null, 'customer', 9999);
        $this->assertSame([], $nobody['rows']);
    }

    public function test_expense_ledger_and_owner_capital_presets(): void
    {
        $ledger = app(LedgerService::class);

        $expenses = $ledger->statement($ledger->presetAccountIds('expense'), '2026-10-01', '2026-10-31');
        $this->assertSame(16700000, $expenses['closing']);                          // 120,000 + 2,000 + 40,000 + 15,000 less 10,000 returned cost
        $this->assertSame(17700000, $expenses['debit']);
        $this->assertSame(1000000, $expenses['credit']);

        $capital = $ledger->statement([Account::byCode('3000')->id], '2026-10-01', '2026-10-31');
        $this->assertSame(50000000, $capital['closing']);                           // a credit-natured account: capital 500,000 shows positive
        $this->assertSame(-1, $capital['sign']);

        $both = $ledger->statement($ledger->presetAccountIds('owner'), '2026-10-01', '2026-10-31');   // capital + drawings mix natures: debit-positive
        $this->assertSame(1, $both['sign']);
        $this->assertSame(-50000000, $both['closing']);
    }

    public function test_party_balances_as_at_a_date(): void
    {
        $ledger = app(PartyLedger::class);

        $now = $ledger->balances('supplier', ['2000'], 'credit');
        $this->assertSame([$this->alZamin->id => 4000000], $now);

        $beforePayment = $ledger->balances('supplier', ['2000'], 'credit', '2026-10-10');
        $this->assertSame([$this->alZamin->id => 10000000], $beforePayment);        // owed 100,000 until the 11-Oct payment

        $this->assertSame([], $ledger->balances('customer', ['1100'], 'debit', '2026-10-04'));   // before the sale
    }

    public function test_listing_reports_show_the_right_documents_and_totals(): void
    {
        $service = app(DocumentReportService::class);

        $sales = $service->build('sales', '2026-10-01', '2026-10-31');
        $this->assertCount(1, $sales['rows']);
        $this->assertSame([18000000, 3240000, 21240000, 12000000, 6000000, 10000000, 9470000], array_map(fn ($i) => $sales['totals'][$i], [5, 6, 7, 8, 9, 10, 11]));
        $this->assertSame('553', $sales['rows'][0][3]);

        $purchases = $service->build('purchases', '2026-10-01', '2026-10-31');
        $this->assertCount(2, $purchases['rows']);
        $this->assertSame(15000000, $purchases['totals'][7]);                       // total
        $this->assertSame(11000000, $purchases['totals'][8]);                       // paid: 60,000 to Al Zamin + 50,000 walk-in
        $this->assertSame(4000000, $purchases['totals'][9]);                        // still owed
        $this->assertSame('Walk-in', $purchases['rows'][1][2]);

        $this->assertSame(1770000, $service->build('sales-returns', '2026-10-01', '2026-10-31')['totals'][6]);
        $this->assertSame(0, $service->build('purchase-returns', '2026-10-01', '2026-10-31')['totals'][6]);
        $this->assertSame(5500000, $service->build('expenses', '2026-10-01', '2026-10-31')['totals'][8]);
        $this->assertSame([10000000, 10000000, 0], array_map(fn ($i) => $service->build('receipts', '2026-10-01', '2026-10-31')['totals'][$i], [6, 7, 8]));
        $this->assertSame(6000000, $service->build('payments', '2026-10-01', '2026-10-31')['totals'][6]);

        $this->assertCount(0, $service->build('sales', '2026-11-01', '2026-11-30')['rows']);        // date range
        $this->assertCount(0, $service->build('sales', '2026-10-01', '2026-10-31', 'cancelled')['rows']);   // status filter
        $this->assertCount(1, $service->build('sales', '2026-10-01', '2026-10-31', 'all', $this->customer->id)['rows']);   // party filter
        $this->assertCount(0, $service->build('sales', '2026-10-01', '2026-10-31', 'all', 9999)['rows']);
    }

    public function test_day_book_lists_every_entry_with_a_count_per_type(): void
    {
        $book = app(DayBookService::class)->build('2026-10-01', '2026-10-31');
        $counts = $book['summary']->map(fn ($s) => $s['count'])->all();

        $this->assertSame(['adjustment' => 1, 'expense' => 2, 'owner' => 1, 'payment' => 1, 'purchase' => 2, 'receipt' => 1, 'sale' => 1, 'sales_return' => 1, 'transfer' => 1], $counts);
        $this->assertCount(11, $book['entries']);

        $one = app(DayBookService::class)->build('2026-10-05', '2026-10-05');
        $this->assertCount(1, $one['entries']);
        $this->assertSame('sale', $one['entries'][0]->voucher_type);
        $this->assertCount(1, app(DayBookService::class)->build('2026-10-01', '2026-10-31', 'receipt')['entries']);
    }
}
