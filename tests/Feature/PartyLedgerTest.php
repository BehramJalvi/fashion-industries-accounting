<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\JournalEntryLine;
use App\Models\Owner;
use App\Models\OwnerTransaction;
use App\Models\Supplier;
use App\Services\Accounting\OpeningBalanceService;
use App\Services\Accounting\OwnerTransactionService;
use App\Services\Accounting\PartyLedger;
use App\Services\Accounting\PostingService;
use App\Support\AccountCodes as A;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\FiscalYearSeeder;
use Database\Seeders\NumberSequenceSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PartyLedgerTest extends TestCase
{
    use RefreshDatabase;

    private PostingService $posting;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([FiscalYearSeeder::class, ChartOfAccountsSeeder::class, NumberSequenceSeeder::class]);
        $this->posting = app(PostingService::class);
    }

    private function journal(string $date, array $lines, string $type = 'journal', ?string $ref = null)
    {
        return $this->posting->post(['date' => $date, 'voucher_type' => $type, 'reference' => $ref, 'description' => 'test', 'lines' => $lines]);
    }

    private function accountBalance(string $code): string
    {
        $r = JournalEntryLine::join('accounts as a', 'a.id', '=', 'journal_entry_lines.account_id')->where('a.code', $code)
            ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) as bal')->value('bal');

        return number_format((float) $r, 2, '.', '');
    }

    public function test_customer_balance_follows_sales_and_receipts(): void
    {
        $c = Customer::create(['code' => 'C001', 'name' => 'Multan Fabrics PVT Limited']);

        $this->journal('2026-10-01', [['account' => A::RECEIVABLE, 'debit' => '69738', 'party' => $c], ['account' => A::SALES, 'credit' => '59100'], ['account' => A::OUTPUT_TAX, 'credit' => '10638']], 'sale', '257');
        $this->assertSame('69738.00', $c->ledgerBalance());

        $this->journal('2026-10-10', [['account' => A::BANK, 'debit' => '20000'], ['account' => A::RECEIVABLE, 'credit' => '20000', 'party' => $c]], 'receipt');
        $this->assertSame('49738.00', $c->ledgerBalance());

        // bulk query used by the list screen must agree with the per-party method
        $bulk = app(PartyLedger::class)->balances('customer', [A::RECEIVABLE], 'debit');
        $this->assertSame(4973800, $bulk[$c->id]);
    }

    public function test_statement_has_running_balance_opening_and_date_filter(): void
    {
        $c = Customer::create(['code' => 'C001', 'name' => 'X']);
        $this->journal('2026-10-01', [['account' => A::RECEIVABLE, 'debit' => '1000', 'party' => $c], ['account' => A::SALES, 'credit' => '1000']], 'sale');
        $this->journal('2026-10-05', [['account' => A::RECEIVABLE, 'debit' => '500', 'party' => $c], ['account' => A::SALES, 'credit' => '500']], 'sale');
        $this->journal('2026-10-09', [['account' => A::CASH, 'debit' => '300'], ['account' => A::RECEIVABLE, 'credit' => '300', 'party' => $c]], 'receipt');

        $all = app(PartyLedger::class)->statement($c);
        $this->assertSame([100000, 150000, 120000], array_column($all['rows'], 'balance'));
        $this->assertSame(120000, $all['closing']);
        $this->assertSame(150000, $all['debit']);
        $this->assertSame(30000, $all['credit']);

        $from = app(PartyLedger::class)->statement($c, '2026-10-05');
        $this->assertSame(100000, $from['opening']);                      // balance before 5 Oct
        $this->assertCount(2, $from['rows']);
        $this->assertSame(120000, $from['closing']);                      // same closing, different starting point
    }

    public function test_cancelled_transaction_stays_visible_and_nets_to_zero(): void
    {
        $c = Customer::create(['code' => 'C001', 'name' => 'X']);
        $sale = $this->journal('2026-10-01', [['account' => A::RECEIVABLE, 'debit' => '700', 'party' => $c], ['account' => A::SALES, 'credit' => '700']], 'sale');
        $this->posting->reverse($sale, 'wrong customer');

        $s = app(PartyLedger::class)->statement($c);
        $this->assertCount(2, $s['rows']);
        $this->assertSame('cancelled', $s['rows'][0]['status']);
        $this->assertSame(0, $s['closing']);
        $this->assertSame('0.00', $c->ledgerBalance());
    }

    public function test_supplier_payable_and_advance(): void
    {
        $s = Supplier::create(['code' => 'S001', 'name' => 'Al Zamin']);

        $this->journal('2026-10-01', [['account' => A::INVENTORY, 'debit' => '195300'], ['account' => A::PAYABLE, 'credit' => '195300', 'party' => $s]], 'purchase');
        $this->assertSame('195300.00', $s->ledgerBalance());              // we owe them

        $this->journal('2026-10-05', [['account' => A::PAYABLE, 'debit' => '200000', 'party' => $s], ['account' => A::BANK, 'credit' => '200000']], 'payment');
        $this->assertSame('-4700.00', $s->ledgerBalance());               // we overpaid: shows as advance
    }

    public function test_employee_is_a_two_way_account(): void
    {
        $e = Employee::create(['code' => 'E001', 'name' => 'Mohsin']);

        $this->journal('2026-10-01', [['account' => '5200', 'debit' => '40000'], ['account' => A::EMPLOYEE_PAYABLE, 'credit' => '40000', 'party' => $e]]);
        $this->assertSame('40000.00', $e->ledgerBalance());

        $this->journal('2026-10-02', [['account' => A::EMPLOYEE_PAYABLE, 'debit' => '40090', 'party' => $e], ['account' => A::BANK, 'credit' => '40090']], 'payment');
        $this->assertSame('-90.00', $e->ledgerBalance());                 // he now owes the business 90

        // money he spends for the business adds to what we owe him
        $this->journal('2026-10-03', [['account' => A::INVENTORY, 'debit' => '600'], ['account' => A::EMPLOYEE_PAYABLE, 'credit' => '600', 'party' => $e]], 'purchase');
        $this->assertSame('510.00', $e->ledgerBalance());
    }

    public function test_owner_capital_and_drawings_with_cancellation(): void
    {
        $saqlain = Owner::create(['name' => 'Saqlain']);
        $svc = app(OwnerTransactionService::class);
        $bank = Account::byCode(A::BANK)->id;

        $draft = $svc->createDraft(['owner_id' => $saqlain->id, 'txn_type' => 'capital', 'txn_date' => '2026-10-01', 'account_id' => $bank, 'amount' => '500000']);
        $this->assertSame('draft', $draft->status);
        $this->assertSame('0.00', $saqlain->ledgerBalance());              // a draft changes nothing

        $svc->post($draft);
        $this->assertSame('500000.00', $saqlain->ledgerBalance());
        $this->assertSame('500000.00', $this->accountBalance(A::BANK));
        $this->assertSame('-500000.00', $this->accountBalance(A::OWNER_CAPITAL));   // credit balance

        $drawing = $svc->createDraft(['owner_id' => $saqlain->id, 'txn_type' => 'drawing', 'txn_date' => '2026-10-05', 'account_id' => $bank, 'amount' => '100000']);
        $svc->post($drawing);
        $this->assertSame('400000.00', $saqlain->ledgerBalance());
        $this->assertSame('400000.00', $this->accountBalance(A::BANK));

        $svc->cancel($drawing, 'wrong amount');
        $this->assertSame('500000.00', $saqlain->ledgerBalance());
        $this->assertSame('cancelled', $drawing->fresh()->status);
        $this->assertSame((string) JournalEntryLine::sum('debit'), (string) JournalEntryLine::sum('credit'));

        // posted / cancelled owner transactions are locked
        $this->expectException(DomainException::class);
        OwnerTransaction::find($draft->id)->update(['amount' => 1]);
    }

    public function test_opening_balances_all_directions_and_once_only(): void
    {
        $opening = app(OpeningBalanceService::class);
        $c = Customer::create(['code' => 'C001', 'name' => 'UH Export']);
        $s = Supplier::create(['code' => 'S001', 'name' => 'Abid Dying']);
        $e = Employee::create(['code' => 'E001', 'name' => 'Mohsin']);

        $opening->record($c, '18760', 'owes_us', '2026-07-01');
        $opening->record($s, '104065.83', 'we_owe', '2026-08-31');
        $opening->record($e, '500', 'owes_us', '2026-07-01');

        $this->assertSame('18760.00', $c->ledgerBalance());
        $this->assertSame('104065.83', $s->ledgerBalance());
        $this->assertSame('-500.00', $e->ledgerBalance());
        // equity side: customer 18,760 (credit) + employee advance 500 (credit) - supplier 104,065.83 (debit) = net debit 84,805.83
        $this->assertSame('84805.83', $this->accountBalance(A::OPENING_EQUITY));
    }

    public function test_opening_balance_cannot_be_recorded_twice_but_can_be_cancelled_and_redone(): void
    {
        $opening = app(OpeningBalanceService::class);
        $c = Customer::create(['code' => 'C001', 'name' => 'UH Export']);

        $opening->record($c, '1000', 'owes_us', '2026-07-01');
        try {
            $opening->record($c, '2000', 'owes_us', '2026-07-01');
            $this->fail('Expected DomainException');
        } catch (DomainException $e) {
            $this->assertStringContainsString('already has an opening balance', $e->getMessage());
        }

        $opening->cancel($c, 'typo');
        $this->assertSame('0.00', $c->ledgerBalance());
        $opening->record($c, '1500', 'owes_us', '2026-07-01');
        $this->assertSame('1500.00', $c->ledgerBalance());
    }

    public function test_owners_have_no_opening_balance_and_a_missing_cancel_is_an_error(): void
    {
        $opening = app(OpeningBalanceService::class);
        $o = Owner::create(['name' => 'Saqlain']);
        $c = Customer::create(['code' => 'C001', 'name' => 'X']);

        $this->assertFalse($opening->supports($o));
        $this->assertTrue($opening->supports($c));

        $this->expectException(DomainException::class);
        $opening->cancel($c);
    }
}
