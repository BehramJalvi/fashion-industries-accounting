<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\Role;
use App\Models\User;
use App\Services\Accounting\PostingService;
use App\Support\AccountCodes;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\FiscalYearSeeder;
use Database\Seeders\NumberSequenceSeeder;
use Database\Seeders\RolePermissionSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChartAndJournalScreensTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, FiscalYearSeeder::class, ChartOfAccountsSeeder::class, NumberSequenceSeeder::class]);
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->roles()->attach(Role::where('name', $role)->first());

        return $u;
    }

    private function manualJournalPayload(): array
    {
        return [
            'entry_date' => '2026-10-05', 'description' => 'Transfer', 'reference' => 'CHQ-1',
            'lines' => [
                ['account_id' => Account::byCode('1000')->id, 'debit' => '40090', 'credit' => ''],
                ['account_id' => Account::byCode('1010')->id, 'debit' => '', 'credit' => '40090'],
            ],
        ];
    }

    public function test_every_engine_account_code_exists_in_the_chart(): void
    {
        foreach ((new \ReflectionClass(AccountCodes::class))->getConstants() as $name => $code) {
            $this->assertTrue(Account::where('code', $code)->exists(), "Missing account {$code} ({$name})");
        }
    }

    public function test_control_accounts_are_bound_to_party_types(): void
    {
        $this->assertSame('customer', Account::byCode('1100')->party_type);
        $this->assertSame('supplier', Account::byCode('2000')->party_type);
        $this->assertSame('employee', Account::byCode('2100')->party_type);
        $this->assertSame('owner', Account::byCode('3000')->party_type);
        $this->assertNull(Account::byCode('1000')->party_type);
    }

    public function test_seeding_twice_does_not_duplicate_or_overwrite(): void
    {
        Account::byCode('5700')->update(['name' => 'Miscellaneous']);
        $count = Account::count();

        $this->seed(ChartOfAccountsSeeder::class);

        $this->assertSame($count, Account::count());
        $this->assertSame('Miscellaneous', Account::byCode('5700')->name);
    }

    public function test_system_accounts_and_accounts_with_postings_cannot_be_deleted(): void
    {
        $this->expectException(DomainException::class);
        Account::byCode('1100')->delete();
    }

    public function test_account_with_postings_cannot_be_deleted(): void
    {
        app(PostingService::class)->post(['date' => '2026-10-05', 'voucher_type' => 'journal', 'lines' => [
            ['account' => '1000', 'debit' => 5], ['account' => '5700', 'credit' => 5],
        ]]);

        $this->expectException(DomainException::class);
        Account::byCode('5700')->delete();
    }

    public function test_accountant_can_view_chart_but_not_change_it(): void
    {
        $accountant = $this->user('accountant');

        $this->actingAs($accountant)->get('/accounts')->assertOk();
        $this->actingAs($accountant)->get('/accounts/create')->assertForbidden();
        $this->actingAs($accountant)->post('/accounts', ['code' => '5900', 'name' => 'X', 'type' => 'expense', 'normal_balance' => 'debit'])->assertForbidden();
    }

    public function test_admin_can_add_an_account_but_not_with_a_duplicate_code(): void
    {
        $admin = $this->user('admin');
        $payload = ['code' => '5900', 'name' => 'Repairs', 'type' => 'expense', 'normal_balance' => 'debit'];

        $this->actingAs($admin)->post('/accounts', $payload)->assertRedirect('/accounts');
        $this->assertDatabaseHas('accounts', ['code' => '5900', 'name' => 'Repairs']);

        $this->actingAs($admin)->post('/accounts', $payload)->assertSessionHasErrors('code');
    }

    public function test_admin_posts_and_cancels_a_manual_journal_through_the_screens(): void
    {
        $admin = $this->user('admin');

        $this->actingAs($admin)->post('/journal', $this->manualJournalPayload())->assertSessionHasNoErrors();
        $entry = JournalEntry::where('voucher_type', 'journal')->firstOrFail();
        $this->assertSame('40090.00', $entry->total_amount);

        $this->actingAs($admin)->post("/journal/{$entry->id}/cancel", ['reason' => 'entered twice'])->assertSessionHasNoErrors();

        $this->assertSame('cancelled', $entry->fresh()->status);
        $this->assertDatabaseHas('journal_entries', ['voucher_type' => 'reversal', 'reverses_entry_id' => $entry->id]);
    }

    public function test_unbalanced_manual_journal_shows_an_error(): void
    {
        $payload = $this->manualJournalPayload();
        $payload['lines'][1]['credit'] = '40000';

        $this->actingAs($this->user('admin'))->post('/journal', $payload)->assertSessionHasErrors('entry');
        $this->assertSame(0, JournalEntry::count());
    }

    public function test_accountant_can_view_but_not_post_or_cancel_journals(): void
    {
        $accountant = $this->user('accountant');
        $this->actingAs($accountant)->get('/journal')->assertOk();
        $this->actingAs($accountant)->post('/journal', $this->manualJournalPayload())->assertForbidden();

        $entry = app(PostingService::class)->post(['date' => '2026-10-05', 'voucher_type' => 'journal', 'lines' => [
            ['account' => '1000', 'debit' => 5], ['account' => '5700', 'credit' => 5],
        ]]);
        $this->actingAs($accountant)->post("/journal/{$entry->id}/cancel", ['reason' => 'x'])->assertForbidden();
    }

    public function test_document_generated_entries_cannot_be_cancelled_from_the_journal_screen(): void
    {
        $source = \App\Models\Customer::create(['code' => 'C001', 'name' => 'Multan Fabrics PVT Limited']);
        $entry = app(PostingService::class)->post([
            'date' => '2026-10-05', 'voucher_type' => 'sale', 'source' => $source,
            'lines' => [['account' => '1000', 'debit' => 5], ['account' => '4000', 'credit' => 5]],
        ]);

        $this->actingAs($this->user('admin'))->post("/journal/{$entry->id}/cancel", ['reason' => 'x'])->assertSessionHas('error');
        $this->assertSame('posted', $entry->fresh()->status);
    }
}
