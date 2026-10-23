<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\JournalEntry;
use App\Models\Owner;
use App\Models\OwnerTransaction;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Accounting\PostingService;
use App\Support\AccountCodes as A;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\FiscalYearSeeder;
use Database\Seeders\NumberSequenceSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PartyScreensTest extends TestCase
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

    private function customerPayload(array $over = []): array
    {
        return array_merge(['name' => 'Multan Fabrics PVT Limited', 'company' => 'Multan Fabrics', 'phone' => '061-1234567',
            'address' => '17-A Export Zone Ansar Colony Multan', 'ntn' => '1290353-1', 'strn' => '04-90-9999-247-82', 'is_active' => '1'], $over);
    }

    public function test_accountant_creates_customers_with_auto_codes_and_default_tax_terms(): void
    {
        $accountant = $this->user('accountant');

        $this->actingAs($accountant)->post('/customers', $this->customerPayload())->assertRedirect('/customers');
        $this->actingAs($accountant)->post('/customers', $this->customerPayload(['name' => 'Ellahi Fabrics']))->assertRedirect('/customers');

        $first = Customer::orderBy('id')->first();
        $this->assertSame('C001', $first->code);
        $this->assertSame('C002', Customer::orderBy('id')->skip(1)->first()->code);
        $this->assertSame('18.00', $first->tax_rate);            // default 18% when left blank
        $this->assertEquals(30, $first->payment_terms_days);
        $this->assertSame('1290353-1', $first->ntn);
        $this->assertSame('04-90-9999-247-82', $first->strn);
    }

    public function test_tax_rate_is_changeable_per_customer_and_exempt_customers_get_zero(): void
    {
        $admin = $this->user('admin');
        $this->actingAs($admin)->post('/customers', $this->customerPayload(['tax_rate' => '5']))->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('/customers', $this->customerPayload(['name' => 'Exempt Co', 'is_tax_exempt' => '1']))->assertSessionHasNoErrors();

        $this->assertSame('5.00', Customer::where('name', 'Multan Fabrics PVT Limited')->first()->effectiveTaxRate());
        $this->assertSame('0.00', Customer::where('name', 'Exempt Co')->first()->effectiveTaxRate());
    }

    public function test_validation(): void
    {
        $admin = $this->user('admin');

        $this->actingAs($admin)->post('/customers', $this->customerPayload(['name' => '']))->assertSessionHasErrors('name');
        $this->actingAs($admin)->post('/customers', $this->customerPayload(['email' => 'not-an-email']))->assertSessionHasErrors('email');
        $this->actingAs($admin)->post('/customers', $this->customerPayload(['tax_rate' => '150']))->assertSessionHasErrors('tax_rate');
        $this->actingAs($admin)->post('/customers', $this->customerPayload(['credit_limit' => '-1']))->assertSessionHasErrors('credit_limit');

        $this->actingAs($admin)->post('/customers', $this->customerPayload(['code' => 'C777']));
        $this->actingAs($admin)->post('/customers', $this->customerPayload(['name' => 'Other', 'code' => 'C777']))->assertSessionHasErrors('code');
    }

    public function test_opening_balance_on_create_needs_journal_permission_and_saves_nothing_when_denied(): void
    {
        $payload = $this->customerPayload(['opening_amount' => '18760', 'opening_side' => 'owes_us', 'opening_date' => '2026-07-01']);

        $this->actingAs($this->user('accountant'))->post('/customers', $payload)->assertForbidden();
        $this->assertSame(0, Customer::count());

        $this->actingAs($this->user('admin'))->post('/customers', $payload)->assertSessionHasNoErrors();
        $this->assertSame('18760.00', Customer::firstOrFail()->ledgerBalance());
        $this->assertSame(1, JournalEntry::where('voucher_type', 'opening')->count());
    }

    public function test_opening_balance_failure_rolls_back_the_new_customer(): void
    {
        $payload = $this->customerPayload(['opening_amount' => '100', 'opening_side' => 'owes_us', 'opening_date' => '2019-01-01']);   // no fiscal year

        $this->actingAs($this->user('admin'))->post('/customers', $payload)->assertSessionHasErrors('opening_amount');
        $this->assertSame(0, Customer::count());
    }

    public function test_list_shows_derived_balance_and_ledger_screen_renders_entries(): void
    {
        $c = Customer::create(['code' => 'C001', 'name' => 'Multan Fabrics PVT Limited']);
        $entry = app(PostingService::class)->post(['date' => '2026-10-01', 'voucher_type' => 'sale', 'reference' => '257', 'lines' => [
            ['account' => A::RECEIVABLE, 'debit' => '69738', 'party' => $c], ['account' => A::SALES, 'credit' => '59100'], ['account' => A::OUTPUT_TAX, 'credit' => '10638'],
        ]]);
        $accountant = $this->user('accountant');

        $this->actingAs($accountant)->get('/customers')->assertOk()->assertSee('69,738.00');
        $this->actingAs($accountant)->get("/customers/{$c->id}/ledger")->assertOk()->assertSee($entry->entry_no)->assertSee('257');
    }

    public function test_delete_rules(): void
    {
        $withHistory = Customer::create(['code' => 'C001', 'name' => 'Has history']);
        $clean = Customer::create(['code' => 'C002', 'name' => 'Clean']);
        app(PostingService::class)->post(['date' => '2026-10-01', 'voucher_type' => 'sale', 'lines' => [
            ['account' => A::RECEIVABLE, 'debit' => '10', 'party' => $withHistory], ['account' => A::SALES, 'credit' => '10']]]);

        $this->actingAs($this->user('accountant'))->delete("/customers/{$clean->id}")->assertForbidden();

        $admin = $this->user('admin');
        $this->actingAs($admin)->delete("/customers/{$withHistory->id}")->assertSessionHas('error');
        $this->assertNotNull($withHistory->fresh());

        $this->actingAs($admin)->delete("/customers/{$clean->id}")->assertRedirect('/customers');
        $this->assertNull(Customer::find($clean->id));
    }

    public function test_suppliers_and_employees_screens(): void
    {
        $accountant = $this->user('accountant');

        $this->actingAs($accountant)->post('/suppliers', ['name' => 'Al Zamin', 'company' => 'Al Zamin Textile Mills', 'is_active' => '1'])->assertRedirect('/suppliers');
        $this->assertSame('S001', Supplier::firstOrFail()->code);

        $this->actingAs($accountant)->post('/employees', ['name' => 'Mohsin', 'designation' => 'Manager', 'is_active' => '1'])->assertRedirect('/employees');
        $employee = Employee::firstOrFail();
        $this->assertSame('E001', $employee->code);

        $this->actingAs($accountant)->get('/suppliers')->assertOk()->assertSee('Al Zamin');
        $this->actingAs($accountant)->get("/employees/{$employee->id}/ledger")->assertOk();
    }

    public function test_opening_balance_endpoints(): void
    {
        $c = Customer::create(['code' => 'C001', 'name' => 'UH Export']);
        $body = ['opening_amount' => '5000', 'opening_side' => 'owes_us', 'opening_date' => '2026-07-01'];

        $this->actingAs($this->user('accountant'))->post("/parties/customer/{$c->id}/opening", $body)->assertForbidden();
        $this->actingAs($this->user('admin'))->post("/parties/owner/1/opening", $body)->assertNotFound();      // owners are not supported

        $admin = $this->user('admin');
        $this->actingAs($admin)->post("/parties/customer/{$c->id}/opening", $body)->assertSessionHasNoErrors();
        $this->assertSame('5000.00', $c->ledgerBalance());

        $this->actingAs($admin)->post("/parties/customer/{$c->id}/opening", $body)->assertSessionHasErrors('opening_amount');   // once only

        $this->actingAs($admin)->delete("/parties/customer/{$c->id}/opening", ['reason' => 'redo'])->assertSessionHas('success');
        $this->assertSame('0.00', $c->ledgerBalance());
    }

    public function test_owner_capital_flow_through_the_screens(): void
    {
        $saqlain = Owner::create(['name' => 'Saqlain']);
        $bank = Account::byCode(A::BANK)->id;
        $payload = ['owner_id' => $saqlain->id, 'txn_type' => 'capital', 'txn_date' => '2026-10-01', 'account_id' => $bank, 'amount' => '500000'];
        $admin = $this->user('admin');

        $this->actingAs($admin)->post('/owner-transactions', $payload + ['action' => 'post'])->assertSessionHasNoErrors();

        $txn = OwnerTransaction::firstOrFail();
        $this->assertSame('posted', $txn->status);
        $this->assertSame('500000.00', $saqlain->ledgerBalance());
        $this->actingAs($admin)->get("/owners/{$saqlain->id}/ledger")->assertOk()->assertSee('500,000.00');

        $this->actingAs($admin)->post("/owner-transactions/{$txn->id}/cancel", ['reason' => ''])->assertSessionHasErrors('reason');
        $this->actingAs($admin)->post("/owner-transactions/{$txn->id}/cancel", ['reason' => 'duplicate'])->assertSessionHas('success');
        $this->assertSame('0.00', $saqlain->ledgerBalance());
    }

    public function test_owner_money_must_go_to_a_cash_or_bank_account(): void
    {
        $saqlain = Owner::create(['name' => 'Saqlain']);

        $this->actingAs($this->user('admin'))->post('/owner-transactions', [
            'owner_id' => $saqlain->id, 'txn_type' => 'capital', 'txn_date' => '2026-10-01', 'account_id' => Account::byCode(A::SALES)->id, 'amount' => '100',
        ])->assertSessionHasErrors('account_id');
        $this->assertSame(0, OwnerTransaction::count());
    }

    public function test_accountant_can_draft_owner_transactions_but_not_post_or_cancel_them(): void
    {
        $saqlain = Owner::create(['name' => 'Saqlain']);
        $payload = ['owner_id' => $saqlain->id, 'txn_type' => 'capital', 'txn_date' => '2026-10-01', 'account_id' => Account::byCode(A::CASH)->id, 'amount' => '1000'];
        $accountant = $this->user('accountant');

        $this->actingAs($accountant)->post('/owner-transactions', $payload + ['action' => 'draft'])->assertSessionHasNoErrors();
        $txn = OwnerTransaction::firstOrFail();
        $this->assertSame('draft', $txn->status);

        $this->actingAs($accountant)->post('/owner-transactions', $payload + ['action' => 'post'])->assertForbidden();
        $this->assertSame(1, OwnerTransaction::count());                       // the forbidden attempt saved nothing
        $this->actingAs($accountant)->post("/owner-transactions/{$txn->id}/post")->assertForbidden();
        $this->actingAs($accountant)->post("/owner-transactions/{$txn->id}/cancel", ['reason' => 'x'])->assertForbidden();
        $this->assertSame('0.00', $saqlain->ledgerBalance());
    }

    public function test_owner_with_transactions_cannot_be_deleted(): void
    {
        $saqlain = Owner::create(['name' => 'Saqlain']);
        $this->actingAs($this->user('admin'))->post('/owner-transactions', [
            'owner_id' => $saqlain->id, 'txn_type' => 'capital', 'txn_date' => '2026-10-01', 'account_id' => Account::byCode(A::CASH)->id, 'amount' => '10', 'action' => 'draft',
        ]);

        $this->actingAs($this->user('admin'))->delete("/owners/{$saqlain->id}")->assertSessionHas('error');
        $this->assertNotNull($saqlain->fresh());
    }
}
