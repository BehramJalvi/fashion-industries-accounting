<?php

use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\Role;
use App\Models\User;
use App\Services\Accounting\PostingService;
use App\Services\Banking\BankReconciliationService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\FiscalYearSeeder;
use Database\Seeders\NumberSequenceSeeder;
use Database\Seeders\RolePermissionSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
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

function bankReconciliationAdmin(): User
{
    $user = User::factory()->create();
    $user->roles()->attach(Role::where('name', 'admin')->firstOrFail());

    return $user;
}

function postBankMovement(string $amount = '100.00', string $date = '2026-07-15'): JournalEntry
{
    return app(PostingService::class)->post([
        'date' => $date,
        'voucher_type' => 'journal',
        'description' => 'Bank reconciliation test movement',
        'lines' => [
            ['account' => '1010', 'debit' => $amount],
            ['account' => '4000', 'credit' => $amount],
        ],
    ]);
}

it('previews a statement file before saving and imports only after confirmation', function () {
    $user = bankReconciliationAdmin();
    $this->actingAs($user);

    $response = $this->post(route('bank-reconciliations.preview'), [
        'account_id' => Account::byCode('1010')->id,
        'period_start' => '2026-07-01',
        'period_end' => '2026-07-31',
        'opening_balance' => '0.00',
        'closing_balance' => '100.00',
        'statement_file' => UploadedFile::fake()->createWithContent(
            'statement.csv',
            "date,description,reference,amount\n2026-07-15,Deposit,DEP-1,100.00\n"
        ),
    ]);

    $response->assertOk()->assertViewHas('lines', fn (array $lines) => count($lines) === 1);
    $this->assertDatabaseCount('bank_reconciliations', 0);
    $this->assertDatabaseCount('bank_reconciliation_lines', 0);
    $token = $response->viewData('previewToken');

    $this->post(route('bank-reconciliations.import'), ['preview_token' => $token])
        ->assertRedirect();

    $this->assertDatabaseHas('bank_reconciliations', [
        'account_id' => Account::byCode('1010')->id,
        'source' => 'csv',
        'status' => 'draft',
    ]);
    $this->assertDatabaseHas('bank_reconciliation_lines', [
        'signed_amount' => '100.00',
        'description' => 'Deposit',
    ]);
});

it('rejects malformed statement rows without persisting a reconciliation', function () {
    $this->actingAs(bankReconciliationAdmin());

    $this->post(route('bank-reconciliations.preview'), [
        'account_id' => Account::byCode('1010')->id,
        'period_start' => '2026-07-01',
        'period_end' => '2026-07-31',
        'opening_balance' => '0.00',
        'closing_balance' => '100.00',
        'statement_file' => UploadedFile::fake()->createWithContent(
            'statement.csv',
            "date,description,reference,amount\n2026-08-15,Outside period,DEP-1,100.00\n"
        ),
    ])->assertSessionHasErrors('statement_file');

    $this->assertDatabaseCount('bank_reconciliations', 0);
    $this->assertDatabaseCount('bank_reconciliation_lines', 0);
});

it('automatically matches an exact unique bank movement and stores an immutable reconciliation summary', function () {
    Auth::login(bankReconciliationAdmin());
    $entry = postBankMovement();
    $entryBefore = [
        $entry->entry_date->toDateString(),
        $entry->status,
        $entry->total_amount,
        $entry->description,
    ];
    $journalLineBefore = $entry->lines->first()->only(['account_id', 'debit', 'credit', 'line_no']);

    $reconciliation = app(BankReconciliationService::class)->createStatement([
        'account_id' => Account::byCode('1010')->id,
        'period_start' => '2026-07-01',
        'period_end' => '2026-07-31',
        'opening_balance' => '0.00',
        'closing_balance' => '100.00',
    ], [[
        'statement_date' => '2026-07-15',
        'description' => 'Deposit',
        'reference' => null,
        'signed_amount' => '100.00',
    ]], 'manual');
    $line = $reconciliation->lines()->firstOrFail();

    expect($line->matched_journal_entry_line_id)->toBe($entry->lines->first()->id)
        ->and(app(BankReconciliationService::class)->summary($reconciliation)['unmatched_statement_count'])->toBe(0);

    $reconciled = app(BankReconciliationService::class)->reconcile($reconciliation);
    $entry->refresh();

    expect($reconciled->status)->toBe('reconciled')
        ->and($reconciled->summary['difference_cents'])->toBe(0)
        ->and([
            $entry->entry_date->toDateString(),
            $entry->status,
            $entry->total_amount,
            $entry->description,
        ])->toBe($entryBefore)
        ->and($entry->lines->first()->only(['account_id', 'debit', 'credit', 'line_no']))->toBe($journalLineBefore);
    $this->assertDatabaseHas('audit_logs', [
        'event' => 'bank_reconciliation.reconciled',
        'auditable_id' => $reconciliation->id,
    ]);
});

it('refuses to reconcile until every balanced statement line is matched', function () {
    Auth::login(bankReconciliationAdmin());
    $reconciliation = app(BankReconciliationService::class)->createStatement([
        'account_id' => Account::byCode('1010')->id,
        'period_start' => '2026-07-01',
        'period_end' => '2026-07-31',
        'opening_balance' => '0.00',
        'closing_balance' => '5.00',
    ], [[
        'statement_date' => '2026-07-15',
        'description' => 'Unrecorded deposit',
        'reference' => null,
        'signed_amount' => '5.00',
    ]], 'manual');

    expect(fn () => app(BankReconciliationService::class)->reconcile($reconciliation))
        ->toThrow(DomainException::class, 'Match every statement line before reconciling.');

    expect($reconciliation->fresh()->status)->toBe('draft')
        ->and($reconciliation->fresh()->summary)->toBeNull();
    $this->assertDatabaseHas('audit_logs', [
        'event' => 'bank_reconciliation.statement_created',
        'auditable_id' => $reconciliation->id,
    ]);
});

it('allows a reviewer to manually match an exact amount on a different date within the period', function () {
    $this->actingAs(bankReconciliationAdmin());
    $entry = postBankMovement();
    $reconciliation = app(BankReconciliationService::class)->createStatement([
        'account_id' => Account::byCode('1010')->id,
        'period_start' => '2026-07-01',
        'period_end' => '2026-07-31',
        'opening_balance' => '0.00',
        'closing_balance' => '100.00',
    ], [[
        'statement_date' => '2026-07-16',
        'description' => 'Deposit posted one day earlier',
        'reference' => null,
        'signed_amount' => '100.00',
    ]], 'manual');
    $line = $reconciliation->lines()->firstOrFail();

    expect($line->matched_journal_entry_line_id)->toBeNull();

    $this->post(route('bank-reconciliations.lines.match', [$reconciliation, $line]), [
        'journal_entry_line_id' => $entry->lines->first()->id,
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($line->fresh()->matched_journal_entry_line_id)->toBe($entry->lines->first()->id);
    $this->assertDatabaseHas('audit_logs', [
        'event' => 'bank_reconciliation.line_matched',
        'auditable_id' => $line->id,
    ]);
});

it('prevents overlapping statement periods for the same bank account', function () {
    Auth::login(bankReconciliationAdmin());
    $service = app(BankReconciliationService::class);
    $header = [
        'account_id' => Account::byCode('1010')->id,
        'period_start' => '2026-07-01',
        'period_end' => '2026-07-31',
        'opening_balance' => '0.00',
        'closing_balance' => '0.00',
    ];
    $service->createStatement($header, [], 'manual');

    expect(fn () => $service->createStatement([
        ...$header,
        'period_start' => '2026-07-15',
        'period_end' => '2026-08-15',
    ], [], 'manual'))->toThrow(DomainException::class, 'overlaps');

    $this->assertDatabaseCount('bank_reconciliations', 1);
});

it('allows draft line correction and removal while leaving posted journal entries untouched', function () {
    $this->actingAs(bankReconciliationAdmin());
    $entry = postBankMovement();
    $service = app(BankReconciliationService::class);
    $reconciliation = $service->createStatement([
        'account_id' => Account::byCode('1010')->id,
        'period_start' => '2026-07-01',
        'period_end' => '2026-07-31',
        'opening_balance' => '0.00',
        'closing_balance' => '100.00',
    ], [[
        'statement_date' => '2026-07-15',
        'description' => 'Deposit',
        'reference' => null,
        'signed_amount' => '100.00',
    ]], 'manual');
    $line = $reconciliation->lines()->firstOrFail();

    $this->get(route('bank-reconciliations.show', $reconciliation))
        ->assertOk()
        ->assertSee('Deposit')
        ->assertSee('Unmatched posted bank transactions');
    $this->get(route('bank-reconciliations.lines.edit', [$reconciliation, $line]))
        ->assertOk()
        ->assertSee('Edit draft statement line');

    $this->patch(route('bank-reconciliations.lines.update', [$reconciliation, $line]), [
        'statement_date' => '2026-07-16',
        'description' => 'Corrected deposit',
        'reference' => 'DEP-CORRECTED',
        'signed_amount' => '99.00',
    ])->assertRedirect();

    expect($line->fresh()->matched_journal_entry_line_id)->toBeNull()
        ->and($line->fresh()->description)->toBe('Corrected deposit')
        ->and($line->fresh()->signed_amount)->toBe('99.00')
        ->and($entry->fresh()->status)->toBe('posted');

    $this->delete(route('bank-reconciliations.lines.destroy', [$reconciliation, $line]))
        ->assertRedirect();

    $this->assertDatabaseMissing('bank_reconciliation_lines', ['id' => $line->id]);
    $this->assertDatabaseHas('journal_entries', ['id' => $entry->id, 'status' => 'posted']);
});

it('forbids users without bank reconciliation permissions from viewing statements', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('bank-reconciliations.index'))
        ->assertForbidden();
});
