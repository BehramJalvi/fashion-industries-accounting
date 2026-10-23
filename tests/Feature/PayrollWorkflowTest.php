<?php

use App\Models\Account;
use App\Models\Employee;
use App\Models\JournalEntryLine;
use App\Models\Role;
use App\Models\User;
use App\Services\Accounting\PostingService;
use App\Services\Payroll\PayrollService;
use App\Support\AccountCodes;
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

function payrollAdmin(): User
{
    $user = User::factory()->create();
    $user->roles()->attach(Role::where('name', 'admin')->firstOrFail());

    return $user;
}

function payrollEmployee(string $salary = '30000.00'): Employee
{
    return Employee::create([
        'code' => 'EMP-'.fake()->unique()->numerify('####'),
        'name' => 'Payroll Employee',
        'monthly_salary' => $salary,
    ]);
}

function salaryPeriodData(array $overrides = []): array
{
    return array_merge([
        'name' => '2026-10',
        'start_date' => '2026-10-01',
        'end_date' => '2026-10-31',
        'working_days' => 20,
    ], $overrides);
}

it('prorates attendance, posts a balanced salary payable and settles it idempotently', function () {
    $this->actingAs(payrollAdmin());
    $employee = payrollEmployee();
    $service = app(PayrollService::class);
    $period = $service->createPeriod(salaryPeriodData());

    app(PostingService::class)->post([
        'date' => '2026-10-02',
        'voucher_type' => 'journal',
        'description' => 'Employee advance',
        'lines' => [
            ['account' => AccountCodes::EMPLOYEE_ADVANCE, 'debit' => '2000.00', 'party' => $employee],
            ['account' => AccountCodes::BANK, 'credit' => '2000.00'],
        ],
    ]);

    $run = $service->createDraftRun($period, [
        $employee->id => [
            'include' => true,
            'present_days' => 18,
            'paid_leave_days' => 1,
            'unpaid_leave_days' => 1,
            'advance_adjustment' => '1000.00',
            'allowances' => [['description' => 'Transport', 'amount' => '1500.00']],
            'deductions' => [['description' => 'Tax', 'amount' => '500.00']],
        ],
    ]);
    $item = $run->items->firstOrFail();

    expect($item->base_pay)->toBe('28500.00')
        ->and($item->net_pay)->toBe('28500.00');

    $service->post($run);
    expect($run->fresh()->status)->toBe('posted')
        ->and($run->fresh()->journalEntry->isBalanced())->toBeTrue()
        ->and($employee->fresh()->ledgerBalance())->toBe('27500.00');

    $paymentData = [
        'payment_date' => '2026-10-31',
        'method' => 'bank',
        'account_id' => Account::byCode(AccountCodes::BANK)->id,
        'amount' => '10000.00',
        'reference' => 'PAYROLL-PART',
        'idempotency_key' => '123e4567-e89b-42d3-a456-426614174000',
    ];
    $firstPayment = $service->settle($item, $paymentData);
    $retryPayment = $service->settle($item, $paymentData);

    expect($retryPayment->id)->toBe($firstPayment->id)
        ->and($item->fresh()->balanceCents())->toBe(1850000)
        ->and($employee->fresh()->ledgerBalance())->toBe('17500.00');
    $this->assertDatabaseCount('payroll_settlements', 1);
    $this->assertSame(
        (string) JournalEntryLine::sum('debit'),
        (string) JournalEntryLine::sum('credit')
    );
});

it('rejects overlapping periods and prevents a second run for the same period', function () {
    $admin = payrollAdmin();
    $this->actingAs($admin);
    $service = app(PayrollService::class);
    $period = $service->createPeriod(salaryPeriodData());
    $employee = payrollEmployee();

    $this->from(route('payroll.periods.create'))
        ->post(route('payroll.periods.store'), salaryPeriodData([
            'name' => '2026-11',
            'start_date' => '2026-10-20',
            'end_date' => '2026-11-19',
        ]))
        ->assertSessionHasErrors('start_date');

    $data = [$employee->id => ['include' => true, 'present_days' => 20]];
    $service->createDraftRun($period, $data);

    expect(fn () => $service->createDraftRun($period, $data))
        ->toThrow(DomainException::class, 'A payroll run already exists');
    $this->assertDatabaseCount('payroll_runs', 1);
});

it('rejects excessive attendance and prevents payroll access without permission', function () {
    $employee = payrollEmployee();
    $period = app(PayrollService::class)->createPeriod(salaryPeriodData());

    expect(fn () => app(PayrollService::class)->createDraftRun($period, [
        $employee->id => [
            'include' => true,
            'present_days' => 20,
            'paid_leave_days' => 1,
        ],
    ]))->toThrow(DomainException::class, 'exceeds the 20 working days');
    $this->assertDatabaseCount('payroll_runs', 0);

    $viewerRole = Role::create(['name' => 'viewer', 'label' => 'Viewer']);
    $viewer = User::factory()->create();
    $viewer->roles()->attach($viewerRole);
    $this->actingAs($viewer)->get(route('payroll.index'))->assertForbidden();
});
