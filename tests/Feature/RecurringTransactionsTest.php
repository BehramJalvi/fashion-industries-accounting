<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Expense;
use App\Models\FundTransfer;
use App\Models\JournalEntry;
use App\Models\RecurringTransactionExecution;
use App\Services\Recurring\RecurringTransactionService;
use App\Support\AccountCodes;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\FiscalYearSeeder;
use Database\Seeders\NumberSequenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecurringTransactionsTest extends TestCase
{
    use RefreshDatabase;

    private RecurringTransactionService $recurring;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([FiscalYearSeeder::class, ChartOfAccountsSeeder::class, NumberSequenceSeeder::class]);
        $this->recurring = app(RecurringTransactionService::class);
    }

    public function test_expense_occurrence_generates_a_reviewable_draft_once_per_scheduled_date(): void
    {
        $template = $this->recurring->createTemplate([
            'name' => 'Monthly rent',
            'transaction_type' => 'expense',
            'frequency' => 'monthly',
            'starts_on' => '2026-10-05',
            'expense_account_id' => Account::byCode('5300')->id,
            'amount' => '25000.00',
            'tax_amount' => '0.00',
            'description' => 'Factory rent',
            'paid_from_account_id' => Account::byCode(AccountCodes::BANK)->id,
        ]);

        $this->assertSame(1, $this->recurring->generateDue(CarbonImmutable::parse('2026-10-05')));
        $this->assertSame(1, Expense::count());
        $this->assertSame(1, RecurringTransactionExecution::count());
        $this->assertSame(0, JournalEntry::count());

        $expense = Expense::firstOrFail();
        $this->assertSame('draft', $expense->status);
        $this->assertSame('2026-10-05', $expense->expense_date->toDateString());
        $this->assertSame('25000.00', $expense->amount);

        $template->forceFill(['next_run_on' => '2026-10-05'])->save();
        $this->assertSame(0, $this->recurring->generateDue(CarbonImmutable::parse('2026-10-05')));
        $this->assertSame(1, Expense::count());
        $this->assertSame(1, RecurringTransactionExecution::count());
    }

    public function test_monthly_occurrences_keep_the_original_day_and_stop_after_the_end_date(): void
    {
        $template = $this->recurring->createTemplate([
            'name' => 'Monthly rent at month end',
            'transaction_type' => 'expense',
            'frequency' => 'monthly',
            'starts_on' => '2026-01-31',
            'ends_on' => '2026-02-28',
            'expense_account_id' => Account::byCode('5300')->id,
            'amount' => '100.00',
            'tax_amount' => '0.00',
            'description' => 'Rent',
            'paid_from_account_id' => Account::byCode(AccountCodes::CASH)->id,
        ]);

        $this->assertSame(2, $this->recurring->generateDue(CarbonImmutable::parse('2026-03-01')));
        $dates = RecurringTransactionExecution::query()->orderBy('scheduled_for')->get()
            ->map(fn (RecurringTransactionExecution $execution) => $execution->scheduled_for->toDateString())->all();

        $this->assertSame(['2026-01-31', '2026-02-28'], $dates);
        $this->assertFalse($template->refresh()->is_active);
    }

    public function test_weekly_occurrences_generate_transfer_drafts_without_posting_them(): void
    {
        $template = $this->recurring->createTemplate([
            'name' => 'Weekly bank transfer',
            'transaction_type' => 'fund_transfer',
            'frequency' => 'weekly',
            'starts_on' => '2026-10-05',
            'from_account_id' => Account::byCode(AccountCodes::BANK)->id,
            'to_account_id' => Account::byCode(AccountCodes::CASH)->id,
            'amount' => '300.00',
            'reference' => 'weekly cash',
        ]);

        $this->assertSame(3, $this->recurring->generateDue(CarbonImmutable::parse('2026-10-19')));
        $this->assertSame(3, FundTransfer::count());
        $this->assertSame(0, FundTransfer::query()->where('status', 'posted')->count());
        $this->assertSame(3, RecurringTransactionExecution::count());
        $this->assertSame('2026-10-26', $template->refresh()->next_run_on->toDateString());
    }
}
