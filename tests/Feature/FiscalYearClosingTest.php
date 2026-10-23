<?php

use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\FiscalYearCloseEvent;
use App\Models\JournalEntry;
use App\Models\Role;
use App\Models\User;
use App\Services\Accounting\FiscalYearClosingService;
use App\Services\Accounting\PostingService;
use App\Services\Reports\IncomeStatementService;
use App\Support\AccountCodes;
use App\Support\Money;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\FiscalYearSeeder;
use Database\Seeders\NumberSequenceSeeder;
use Database\Seeders\RolePermissionSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed([
        RolePermissionSeeder::class,
        FiscalYearSeeder::class,
        ChartOfAccountsSeeder::class,
        NumberSequenceSeeder::class,
    ]);
});

function fiscalClosingAdmin(): User
{
    $user = User::factory()->create();
    $user->roles()->attach(Role::where('name', 'admin')->firstOrFail());

    return $user;
}

function postFiscalYearProfitAndLoss(int $incomeCents = 100000, int $expenseCents = 30000): JournalEntry
{
    return app(PostingService::class)->post([
        'date' => '2027-06-30',
        'voucher_type' => 'journal',
        'description' => 'Fiscal year test income and expense',
        'lines' => [
            ['account' => '1000', 'debit' => Money::fromCents($incomeCents)],
            ['account' => '4000', 'credit' => Money::fromCents($incomeCents)],
            ['account' => '5300', 'debit' => Money::fromCents($expenseCents)],
            ['account' => '1000', 'credit' => Money::fromCents($expenseCents)],
        ],
    ]);
}

it('closes profit and loss balances to retained earnings and records an idempotent audit event', function () {
    $this->actingAs(fiscalClosingAdmin());
    $year = FiscalYear::where('name', '2026-27')->firstOrFail();
    postFiscalYearProfitAndLoss();

    $response = $this->post(route('fiscal-years.close', $year));

    $response->assertRedirect()->assertSessionHasNoErrors();
    $year->refresh();
    $closeEvent = FiscalYearCloseEvent::where('event_type', 'close')->firstOrFail();
    $closeEntry = $closeEvent->journalEntry()->with('lines')->firstOrFail();
    $closingLines = $closeEntry->lines->keyBy('account_id');

    expect($year->is_closed)->toBeTrue()
        ->and($closeEntry->voucher_type)->toBe('year_end_close')
        ->and($closeEntry->isBalanced())->toBeTrue()
        ->and($closingLines[Account::byCode('4000')->id]->debit)->toBe('1000.00')
        ->and($closingLines[Account::byCode('5300')->id]->credit)->toBe('300.00')
        ->and($closingLines[Account::byCode(AccountCodes::RETAINED_EARNINGS)->id]->credit)->toBe('700.00');
    $this->assertDatabaseHas('audit_logs', [
        'event' => 'created',
        'auditable_type' => 'fiscal_year_close_event',
        'auditable_id' => $closeEvent->id,
    ]);
    expect(app(IncomeStatementService::class)->build('2026-07-01', '2027-06-30')['net_profit'])
        ->toBe(70000);

    app(FiscalYearClosingService::class)->close($year->fresh());

    $this->assertDatabaseCount('fiscal_year_close_events', 1);
    $this->assertDatabaseCount('journal_entries', 2);
});

it('reopens by reversing the close in the same year and supports a later close cycle', function () {
    $this->actingAs(fiscalClosingAdmin());
    $year = FiscalYear::where('name', '2026-27')->firstOrFail();
    postFiscalYearProfitAndLoss();
    $service = app(FiscalYearClosingService::class);

    $close = $service->close($year);
    $reopen = $service->reopen($year, 'Correcting a year-end adjustment');

    expect($year->fresh()->is_closed)->toBeFalse()
        ->and($close->journalEntry->fresh()->status)->toBe('cancelled')
        ->and($reopen->cycle)->toBe(1)
        ->and($reopen->reversalEntry->entry_date->toDateString())->toBe('2027-06-30')
        ->and(app(IncomeStatementService::class)->build('2026-07-01', '2027-06-30')['net_profit'])
        ->toBe(70000);
    $this->assertDatabaseCount('fiscal_year_close_events', 2);

    $secondClose = $service->close($year->fresh());

    expect($secondClose->cycle)->toBe(2)
        ->and($year->fresh()->is_closed)->toBeTrue();
    $this->assertDatabaseCount('fiscal_year_close_events', 3);
    $this->assertDatabaseCount('journal_entries', 4);
});

it('rejects unsafe closing conditions without partially closing the year', function () {
    $year = FiscalYear::where('name', '2026-27')->firstOrFail();
    postFiscalYearProfitAndLoss();
    FiscalYear::create([
        'name' => 'overlapping-test-year',
        'start_date' => '2027-06-01',
        'end_date' => '2027-07-31',
        'is_closed' => false,
    ]);

    expect(fn () => app(FiscalYearClosingService::class)->close($year))
        ->toThrow(DomainException::class, 'Overlapping fiscal years must be corrected before closing.');

    $year->refresh();
    expect($year->is_closed)->toBeFalse();
    $this->assertDatabaseCount('fiscal_year_close_events', 0);
    $this->assertDatabaseCount('journal_entries', 1);
});

it('allows accountants to close but requires the explicit reopen permission and a reason', function () {
    $accountant = User::factory()->create();
    $accountant->roles()->attach(Role::where('name', 'accountant')->firstOrFail());
    $year = FiscalYear::where('name', '2026-27')->firstOrFail();
    postFiscalYearProfitAndLoss();
    $this->actingAs($accountant);

    $this->get(route('fiscal-years.index'))->assertOk();
    $this->post(route('fiscal-years.close', $year))->assertRedirect();
    $year->refresh();
    expect($year->is_closed)->toBeTrue();

    $this->post(route('fiscal-years.reopen', $year), ['reason' => 'Not authorized'])
        ->assertForbidden();
    $this->assertDatabaseCount('fiscal_year_close_events', 1);

    $this->actingAs(fiscalClosingAdmin())
        ->post(route('fiscal-years.reopen', $year), [])
        ->assertSessionHasErrors('reason');
    expect($year->fresh()->is_closed)->toBeTrue();
});
