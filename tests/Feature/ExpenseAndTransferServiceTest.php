<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\FundTransfer;
use App\Models\JournalEntryLine;
use App\Models\Supplier;
use App\Services\Payments\ExpenseService;
use App\Services\Payments\FundTransferService;
use App\Services\Payments\SettlementService;
use App\Support\AccountCodes as A;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\FiscalYearSeeder;
use Database\Seeders\NumberSequenceSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpenseAndTransferServiceTest extends TestCase
{
    use RefreshDatabase;

    private ExpenseService $expenses;
    private FundTransferService $transfers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([FiscalYearSeeder::class, ChartOfAccountsSeeder::class, NumberSequenceSeeder::class]);
        $this->expenses = app(ExpenseService::class);
        $this->transfers = app(FundTransferService::class);
    }

    private function id(string $code): int { return Account::byCode($code)->id; }

    private function gl(string $code): string
    {
        $r = JournalEntryLine::join('accounts as a', 'a.id', '=', 'journal_entry_lines.account_id')->where('a.code', $code)
            ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) as bal')->value('bal');

        return number_format((float) $r, 2, '.', '');
    }

    private function assertJournalBalances(): void
    {
        $this->assertSame((string) JournalEntryLine::sum('debit'), (string) JournalEntryLine::sum('credit'));
    }

    private function expense(array $over = []): Expense
    {
        return $this->expenses->createDraft(array_merge(['expense_date' => '2026-10-05', 'expense_account_id' => $this->id('5300'), 'amount' => 15000, 'description' => 'Factory rent'], $over));
    }

    // ------------------------------------------------------------------ expenses

    public function test_expense_paid_in_cash(): void
    {
        $e = $this->expenses->post($this->expense(['paid_from_account_id' => $this->id(A::CASH)]));

        $this->assertSame('posted', $e->status);
        $this->assertSame('EXP-0001', $e->expense_no);
        $this->assertSame('15000.00', $this->gl('5300'));
        $this->assertSame('-15000.00', $this->gl(A::CASH));
        $this->assertJournalBalances();
    }

    public function test_input_tax_is_booked_separately_and_paid_with_the_expense(): void
    {
        $this->expenses->post($this->expense(['amount' => 1000, 'tax_amount' => 180, 'paid_from_account_id' => $this->id(A::BANK)]));

        $this->assertSame('1000.00', $this->gl('5300'));
        $this->assertSame('180.00', $this->gl(A::INPUT_TAX));
        $this->assertSame('-1180.00', $this->gl(A::BANK));
        $this->assertJournalBalances();
    }

    public function test_abid_dyings_processing_bill_is_owed_then_paid(): void
    {
        $abid = Supplier::create(['code' => 'S002', 'name' => 'Abid Dying']);

        $this->expenses->post($this->expense(['expense_account_id' => $this->id(A::PROCESSING_CHARGES), 'amount' => '104065.83',
            'description' => 'Processing of raw material', 'payee_type' => 'supplier', 'payee_id' => $abid->id]));

        $this->assertSame('104065.83', $abid->ledgerBalance());
        $this->assertSame('104065.83', $this->gl(A::PROCESSING_CHARGES));
        $this->assertSame('0.00', $this->gl(A::CASH));                      // nothing paid yet

        $svc = app(SettlementService::class);
        $svc->post($svc->createDraft('payment', ['payee_type' => 'supplier', 'payee_id' => $abid->id, 'payment_date' => '2026-10-20', 'method' => 'bank',
            'account_id' => $this->id(A::BANK), 'amount' => 100000]));

        $this->assertSame('4065.83', $abid->ledgerBalance());
        $this->assertJournalBalances();
    }

    public function test_money_an_employee_spent_for_the_business_is_owed_back_to_him(): void
    {
        $mohsin = Employee::create(['code' => 'E001', 'name' => 'Mohsin']);

        $this->expenses->post($this->expense(['expense_account_id' => $this->id('5500'), 'amount' => 2500, 'description' => 'Transport paid by Mohsin', 'payee_type' => 'employee', 'payee_id' => $mohsin->id]));

        $this->assertSame('2500.00', $mohsin->ledgerBalance());
        $this->assertSame('2500.00', $this->gl('5500'));
    }

    public function test_expense_rules(): void
    {
        $supplier = Supplier::create(['code' => 'S001', 'name' => 'Al Zamin']);
        $cash = $this->id(A::CASH);
        Account::byCode('5700')->update(['is_active' => false]);

        $cases = [
            'both paid-from and owed-to'   => [['paid_from_account_id' => $cash, 'payee_type' => 'supplier', 'payee_id' => $supplier->id], 'not both'],
            'neither'                      => [[], 'Say how it was paid'],
            'a bank account as the expense' => [['expense_account_id' => $this->id(A::BANK), 'paid_from_account_id' => $cash], 'active expense account'],
            'sales as the expense'         => [['expense_account_id' => $this->id(A::SALES), 'paid_from_account_id' => $cash], 'active expense account'],
            'receivable as the expense'    => [['expense_account_id' => $this->id(A::RECEIVABLE), 'paid_from_account_id' => $cash], 'active expense account'],
            'an inactive expense account'  => [['expense_account_id' => $this->id('5700'), 'paid_from_account_id' => $cash], 'active expense account'],
            'zero amount'                  => [['amount' => 0, 'paid_from_account_id' => $cash], 'greater than zero'],
            'negative tax'                 => [['tax_amount' => -1, 'paid_from_account_id' => $cash], 'cannot be negative'],
            'paid from a non-cash account' => [['paid_from_account_id' => $this->id(A::SALES)], 'cash or bank account'],
            'unknown supplier'             => [['payee_type' => 'supplier', 'payee_id' => 9999], 'does not exist'],
            'unknown payee type'           => [['payee_type' => 'customer', 'payee_id' => 1], 'supplier or an employee'],
        ];

        foreach ($cases as $label => [$over, $expected]) {
            try {
                $this->expense($over);
                $this->fail("Expected DomainException for: {$label}");
            } catch (DomainException $e) {
                $this->assertStringContainsString($expected, $e->getMessage(), $label);
            }
        }
        $this->assertSame(0, Expense::count());
    }

    public function test_cancelling_an_expense_reverses_it_and_posted_ones_are_locked(): void
    {
        $e = $this->expenses->post($this->expense(['paid_from_account_id' => $this->id(A::CASH)]));

        try {
            $e->update(['amount' => 1]);
            $this->fail('Expected DomainException');
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }

        $e = $this->expenses->cancel($e, 'duplicate entry');
        $this->assertSame('cancelled', $e->status);
        $this->assertSame('0.00', $this->gl('5300'));
        $this->assertSame('0.00', $this->gl(A::CASH));
        $this->assertJournalBalances();

        $this->expectException(DomainException::class);
        $this->expenses->cancel($e, 'again');
    }

    // ------------------------------------------------------------------ fund transfers

    public function test_bank_to_cash_transfer_moves_money_without_profit_or_loss(): void
    {
        // the cheque of 40,090 cashed at the bank
        $t = $this->transfers->post($this->transfers->createDraft(['transfer_date' => '2026-10-05', 'from_account_id' => $this->id(A::BANK), 'to_account_id' => $this->id(A::CASH),
            'amount' => 40090, 'reference' => 'CHQ-118', 'notes' => 'Cheque cashed by Mohsin']));

        $this->assertSame('TRF-0001', $t->transfer_no);
        $this->assertSame('40090.00', $this->gl(A::CASH));
        $this->assertSame('-40090.00', $this->gl(A::BANK));
        $this->assertSame('0.00', number_format(
            (float) JournalEntryLine::join('accounts as a', 'a.id', '=', 'journal_entry_lines.account_id')->whereIn('a.type', ['revenue', 'cogs', 'expense'])->sum('debit')
            + (float) JournalEntryLine::join('accounts as a', 'a.id', '=', 'journal_entry_lines.account_id')->whereIn('a.type', ['revenue', 'cogs', 'expense'])->sum('credit'), 2, '.', ''));
        $this->assertJournalBalances();
    }

    public function test_transfer_rules_cancellation_and_locking(): void
    {
        $bank = $this->id(A::BANK);
        $cash = $this->id(A::CASH);
        $make = fn (array $over = []) => $this->transfers->createDraft(array_merge(['transfer_date' => '2026-10-05', 'from_account_id' => $bank, 'to_account_id' => $cash, 'amount' => 1000], $over));

        $cases = [
            'same account'         => [['to_account_id' => $bank], 'two different accounts'],
            'zero'                 => [['amount' => 0], 'greater than zero'],
            'paying from sales'    => [['from_account_id' => $this->id(A::SALES)], 'paying account'],
            'receiving into AR'    => [['to_account_id' => $this->id(A::RECEIVABLE)], 'receiving account'],
        ];
        foreach ($cases as $label => [$over, $expected]) {
            try {
                $make($over);
                $this->fail("Expected DomainException for: {$label}");
            } catch (DomainException $e) {
                $this->assertStringContainsString($expected, $e->getMessage(), $label);
            }
        }
        $this->assertSame(0, FundTransfer::count());

        $t = $this->transfers->post($make());
        try {
            $t->update(['amount' => 5]);
            $this->fail('Expected DomainException');
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }

        $this->transfers->cancel($t, 'wrong date');
        $this->assertSame('0.00', $this->gl(A::CASH));
        $this->assertSame('0.00', $this->gl(A::BANK));
        $this->assertJournalBalances();
    }
}
