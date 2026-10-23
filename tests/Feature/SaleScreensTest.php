<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SalesReturn;
use App\Models\Unit;
use App\Models\User;
use App\Services\Inventory\OpeningStockService;
use App\Support\AccountCodes as A;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\FiscalYearSeeder;
use Database\Seeders\NumberSequenceSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SaleScreensTest extends TestCase
{
    use RefreshDatabase;

    private Product $thread;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, FiscalYearSeeder::class, ChartOfAccountsSeeder::class, NumberSequenceSeeder::class]);
        $this->thread = Product::create(['code' => 'P1', 'name' => 'Grey Thread', 'unit_id' => Unit::create(['name' => 'Bundle'])->id, 'product_type' => 'finished_good', 'purchase_price' => 0, 'selling_price' => 100]);
        app(OpeningStockService::class)->record($this->thread, 1000, 60, '2026-10-01');
        $this->customer = Customer::create(['code' => 'C001', 'name' => 'Multan Fabrics PVT Limited', 'is_active' => true]);
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->roles()->attach(Role::where('name', $role)->first());

        return $u;
    }

    private function payload(array $over = []): array
    {
        return array_merge([
            'customer_id' => $this->customer->id, 'sale_date' => '2026-10-05', 'gate_pass_no' => '553',
            'items' => [['product_id' => $this->thread->id, 'qty' => '100', 'unit_price' => '100']],
        ], $over);
    }

    public function test_accountant_enters_and_posts_an_invoice_with_the_customers_tax_rate(): void
    {
        $accountant = $this->user('accountant');

        $this->actingAs($accountant)->post('/sales', $this->payload(['action' => 'post']))->assertSessionHasNoErrors();

        $sale = Sale::firstOrFail();
        $this->assertSame('posted', $sale->status);
        $this->assertSame('258', $sale->invoice_no);
        $this->assertSame('11800.00', $sale->total);                       // tax left blank -> customer's 18%
        $this->assertSame('11800.00', $this->customer->ledgerBalance());
        $this->assertSame('900.000', $this->thread->currentStock());

        $this->actingAs($accountant)->get("/sales/{$sale->id}")->assertOk()->assertSee('Invoice 258')->assertSee('553')->assertSee('11,800.00');
        $this->actingAs($accountant)->get('/sales')->assertOk()->assertSee('Multan Fabrics PVT Limited')->assertSee('258');
        $this->actingAs($accountant)->get("/customers/{$this->customer->id}/ledger")->assertOk()->assertSee('11,800.00');
    }

    public function test_an_explicit_zero_tax_rate_is_respected(): void
    {
        $this->actingAs($this->user('accountant'))->post('/sales', $this->payload([
            'items' => [['product_id' => $this->thread->id, 'qty' => '10', 'unit_price' => '100', 'tax_rate' => '0']], 'action' => 'post',
        ]))->assertSessionHasNoErrors();

        $this->assertSame('1000.00', Sale::firstOrFail()->total);
    }

    public function test_cash_sale_through_the_form(): void
    {
        $this->actingAs($this->user('accountant'))->post('/sales', $this->payload([
            'received_now' => '11800', 'received_into_account_id' => Account::byCode(A::CASH)->id, 'action' => 'post',
        ]))->assertSessionHasNoErrors();

        $this->assertSame('paid', Sale::firstOrFail()->paymentStatus());
        $this->assertSame('0.00', $this->customer->ledgerBalance());
    }

    public function test_validation_errors_save_nothing(): void
    {
        $accountant = $this->user('accountant');

        $this->actingAs($accountant)->post('/sales', $this->payload(['customer_id' => '']))->assertSessionHasErrors('customer_id');
        $this->actingAs($accountant)->post('/sales', $this->payload(['items' => []]))->assertSessionHasErrors('items');
        $this->actingAs($accountant)->post('/sales', $this->payload(['items' => [['product_id' => $this->thread->id, 'qty' => '0', 'unit_price' => '10']]]))->assertSessionHasErrors('items.0.qty');
        $this->actingAs($accountant)->post('/sales', $this->payload(['items' => [['product_id' => $this->thread->id, 'qty' => '1', 'unit_price' => '-5']]]))->assertSessionHasErrors('items.0.unit_price');
        $this->actingAs($accountant)->post('/sales', $this->payload(['items' => [['product_id' => $this->thread->id, 'qty' => '1', 'unit_price' => '5', 'tax_rate' => '150']]]))->assertSessionHasErrors('items.0.tax_rate');
        $this->actingAs($accountant)->post('/sales', $this->payload(['items' => [['product_id' => 9999, 'qty' => '1', 'unit_price' => '5']]]))->assertSessionHasErrors('items.0.product_id');

        $this->customer->update(['is_active' => false]);
        $this->actingAs($accountant)->post('/sales', $this->payload())->assertSessionHasErrors('customer_id');

        $this->assertSame(0, Sale::count());
    }

    public function test_not_enough_stock_keeps_a_draft_and_explains_why(): void
    {
        $this->actingAs($this->user('accountant'))->post('/sales', $this->payload([
            'items' => [['product_id' => $this->thread->id, 'qty' => '5000', 'unit_price' => '100']], 'action' => 'post',
        ]))->assertSessionHas('error');

        $sale = Sale::firstOrFail();
        $this->assertSame('draft', $sale->status);
        $this->assertStringStartsWith('DRAFT-', $sale->invoice_no);
        $this->assertSame('1000.000', $this->thread->currentStock());
    }

    public function test_credit_limit_keeps_a_draft_and_explains_why(): void
    {
        $this->customer->update(['credit_limit' => '5000']);

        $this->actingAs($this->user('accountant'))->post('/sales', $this->payload(['action' => 'post']))->assertSessionHas('error');   // 11,800 > 5,000

        $this->assertSame('draft', Sale::firstOrFail()->status);
        $this->assertSame('0.00', $this->customer->ledgerBalance());
    }

    public function test_draft_can_be_edited_then_posted_but_a_posted_invoice_cannot_be_edited(): void
    {
        $accountant = $this->user('accountant');
        $this->actingAs($accountant)->post('/sales', $this->payload(['action' => 'draft']))->assertSessionHasNoErrors();
        $sale = Sale::firstOrFail();
        $this->assertSame('1000.000', $this->thread->currentStock());

        $this->actingAs($accountant)->get("/sales/{$sale->id}/edit")->assertOk();
        $this->actingAs($accountant)->put("/sales/{$sale->id}", $this->payload(['items' => [['product_id' => $this->thread->id, 'qty' => '10', 'unit_price' => '50', 'tax_rate' => '0']]]))->assertSessionHasNoErrors();
        $this->assertSame('500.00', $sale->fresh()->total);

        $this->actingAs($accountant)->post("/sales/{$sale->id}/post")->assertSessionHas('success');
        $this->assertSame('990.000', $this->thread->currentStock());

        $this->actingAs($accountant)->get("/sales/{$sale->id}/edit")->assertRedirect("/sales/{$sale->id}");
        $this->actingAs($accountant)->put("/sales/{$sale->id}", $this->payload())->assertSessionHasErrors('sale');
    }

    public function test_only_drafts_can_be_deleted_and_a_refused_delete_keeps_the_lines(): void
    {
        $accountant = $this->user('accountant');
        $this->actingAs($accountant)->post('/sales', $this->payload(['action' => 'draft']));
        $draft = Sale::firstOrFail();
        $this->actingAs($accountant)->delete("/sales/{$draft->id}")->assertRedirect('/sales');
        $this->assertNull(Sale::find($draft->id));

        $this->actingAs($accountant)->post('/sales', $this->payload(['action' => 'post']));
        $posted = Sale::firstOrFail();
        $this->actingAs($accountant)->delete("/sales/{$posted->id}")->assertSessionHas('error');
        $this->assertNotNull(Sale::find($posted->id));
        $this->assertSame(1, $posted->items()->count());
    }

    public function test_cancel_is_admin_only_needs_a_reason_and_reverses_everything(): void
    {
        $this->actingAs($this->user('accountant'))->post('/sales', $this->payload(['action' => 'post']));
        $sale = Sale::firstOrFail();

        $this->actingAs($this->user('accountant'))->post("/sales/{$sale->id}/cancel", ['reason' => 'x'])->assertForbidden();

        $admin = $this->user('admin');
        $this->actingAs($admin)->post("/sales/{$sale->id}/cancel", ['reason' => ''])->assertSessionHasErrors('reason');
        $this->actingAs($admin)->post("/sales/{$sale->id}/cancel", ['reason' => 'wrong customer'])->assertSessionHas('success');

        $this->assertSame('cancelled', $sale->fresh()->status);
        $this->assertSame('258', $sale->fresh()->invoice_no);
        $this->assertSame('1000.000', $this->thread->currentStock());
        $this->assertSame('0.00', $this->customer->ledgerBalance());
    }

    public function test_sales_return_flow_through_the_screens(): void
    {
        $accountant = $this->user('accountant');
        $this->actingAs($accountant)->post('/sales', $this->payload(['action' => 'post']));
        $sale = Sale::firstOrFail();

        $this->actingAs($accountant)->get('/sales-returns/create')->assertOk()->assertSee('258');
        $this->actingAs($accountant)->get('/sales-returns/create?sale_id=' . $sale->id)->assertOk()->assertSee('Can still return');

        $this->actingAs($accountant)->post('/sales-returns', [
            'sale_id' => $sale->id, 'return_date' => '2026-10-10', 'reason' => 'Wrong shade', 'qty' => [$this->thread->id => '30'], 'action' => 'post',
        ])->assertSessionHasNoErrors();

        $r = SalesReturn::firstOrFail();
        $this->assertSame('posted', $r->status);
        $this->assertSame('3540.00', $r->total);
        $this->assertSame('8260.00', $this->customer->ledgerBalance());
        $this->assertSame('930.000', $this->thread->currentStock());
        $this->actingAs($accountant)->get('/sales-returns')->assertOk()->assertSee('SRT-0001');
        $this->actingAs($accountant)->get("/sales-returns/{$r->id}")->assertOk()->assertSee('Wrong shade');

        $this->actingAs($accountant)->post("/sales-returns/{$r->id}/cancel", ['reason' => 'x'])->assertForbidden();
        $this->actingAs($this->user('admin'))->post("/sales-returns/{$r->id}/cancel", ['reason' => 'customer kept them'])->assertSessionHas('success');
        $this->assertSame('11800.00', $this->customer->ledgerBalance());
    }

    public function test_return_errors_are_shown_and_nothing_is_saved(): void
    {
        $accountant = $this->user('accountant');
        $this->actingAs($accountant)->post('/sales', $this->payload(['action' => 'post']));
        $sale = Sale::firstOrFail();

        $this->actingAs($accountant)->post('/sales-returns', ['sale_id' => $sale->id, 'return_date' => '2026-10-10', 'qty' => [$this->thread->id => '500']])->assertSessionHasErrors('return');
        $this->actingAs($accountant)->post('/sales-returns', ['sale_id' => $sale->id, 'return_date' => '2026-10-10', 'qty' => [$this->thread->id => '0']])->assertSessionHasErrors('return');
        $this->assertSame(0, SalesReturn::count());
    }

    public function test_guests_and_unauthorised_users_are_blocked(): void
    {
        $this->get('/sales')->assertRedirect('/login');

        $viewer = User::factory()->create();      // no role
        $this->actingAs($viewer)->get('/sales')->assertForbidden();
        $this->actingAs($viewer)->post('/sales', $this->payload())->assertForbidden();
    }
}
