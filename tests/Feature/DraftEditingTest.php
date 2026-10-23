<?php

use App\Models\Account;
use App\Models\Expense;
use App\Models\FundTransfer;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\FiscalYearSeeder;
use Database\Seeders\NumberSequenceSeeder;
use Database\Seeders\RolePermissionSeeder;
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

    $user = User::factory()->create();
    $user->roles()->attach(Role::where('name', 'admin')->firstOrFail());
    $this->actingAs($user);
});

it('edits and validates expense drafts without creating an additional document', function () {
    $response = $this->post(route('expenses.store'), [
        'expense_date' => '2026-10-05',
        'expense_account_id' => Account::byCode('5300')->id,
        'amount' => '100.00',
        'tax_amount' => '0.00',
        'description' => 'Original description',
        'paid_from_account_id' => Account::byCode('1000')->id,
        'action' => 'draft',
    ]);
    $expense = Expense::firstOrFail();

    $response->assertRedirect(route('expenses.show', $expense));
    $this->get(route('expenses.edit', $expense))->assertOk()->assertSee('Edit draft expense');

    $this->put(route('expenses.update', $expense), [
        'expense_date' => '2026-10-06',
        'expense_account_id' => Account::byCode('5400')->id,
        'amount' => '125.50',
        'tax_amount' => '5.00',
        'description' => 'Corrected description',
        'paid_from_account_id' => Account::byCode('1000')->id,
        'action' => 'draft',
    ])->assertRedirect(route('expenses.show', $expense));

    expect($expense->fresh()->status)->toBe('draft')
        ->and($expense->fresh()->expense_date->toDateString())->toBe('2026-10-06')
        ->and($expense->fresh()->expenseAccount->code)->toBe('5400')
        ->and($expense->fresh()->amount)->toBe('125.50')
        ->and($expense->fresh()->tax_amount)->toBe('5.00')
        ->and($expense->fresh()->description)->toBe('Corrected description');
    $this->assertDatabaseCount('expenses', 1);
    $this->assertDatabaseCount('journal_entries', 0);
});

it('edits fund transfer drafts but rejects invalid account combinations', function () {
    $response = $this->post(route('fund-transfers.store'), [
        'transfer_date' => '2026-10-05',
        'from_account_id' => Account::byCode('1010')->id,
        'to_account_id' => Account::byCode('1000')->id,
        'amount' => '300.00',
        'reference' => 'TR-OLD',
        'notes' => 'Original note',
        'action' => 'draft',
    ]);
    $transfer = FundTransfer::firstOrFail();

    $response->assertRedirect(route('fund-transfers.show', $transfer));
    $this->get(route('fund-transfers.edit', $transfer))->assertOk()->assertSee('Edit draft fund transfer');

    $this->put(route('fund-transfers.update', $transfer), [
        'transfer_date' => '2026-10-07',
        'from_account_id' => Account::byCode('1000')->id,
        'to_account_id' => Account::byCode('1010')->id,
        'amount' => '450.25',
        'reference' => 'TR-NEW',
        'notes' => 'Corrected note',
        'action' => 'draft',
    ])->assertRedirect(route('fund-transfers.show', $transfer));

    expect($transfer->fresh()->transfer_date->toDateString())->toBe('2026-10-07')
        ->and($transfer->fresh()->from_account_id)->toBe(Account::byCode('1000')->id)
        ->and($transfer->fresh()->to_account_id)->toBe(Account::byCode('1010')->id)
        ->and($transfer->fresh()->amount)->toBe('450.25')
        ->and($transfer->fresh()->reference)->toBe('TR-NEW');

    $this->put(route('fund-transfers.update', $transfer), [
        'transfer_date' => '2026-10-07',
        'from_account_id' => Account::byCode('1000')->id,
        'to_account_id' => Account::byCode('1000')->id,
        'amount' => '500.00',
        'action' => 'draft',
    ])->assertSessionHasErrors('to_account_id');

    $this->assertDatabaseCount('fund_transfers', 1);
});

it('keeps posted expense drafts immutable through the update endpoint', function () {
    $this->post(route('expenses.store'), [
        'expense_date' => '2026-10-05',
        'expense_account_id' => Account::byCode('5300')->id,
        'amount' => '100.00',
        'tax_amount' => '0.00',
        'description' => 'Posted expense',
        'paid_from_account_id' => Account::byCode('1000')->id,
        'action' => 'post',
    ])->assertRedirect();
    $expense = Expense::firstOrFail();

    $this->put(route('expenses.update', $expense), [
        'expense_date' => '2026-10-06',
        'expense_account_id' => Account::byCode('5300')->id,
        'amount' => '200.00',
        'tax_amount' => '0.00',
        'description' => 'Attempted change',
        'paid_from_account_id' => Account::byCode('1000')->id,
        'action' => 'draft',
    ])->assertSessionHasErrors('expense');

    expect($expense->fresh()->status)->toBe('posted')
        ->and($expense->fresh()->amount)->toBe('100.00')
        ->and($expense->fresh()->description)->toBe('Posted expense');
    $this->assertDatabaseCount('journal_entries', 1);
});
