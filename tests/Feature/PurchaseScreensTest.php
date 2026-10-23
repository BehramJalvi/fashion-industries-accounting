<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\Purchasing\PurchaseService;
use App\Support\AccountCodes as A;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\FiscalYearSeeder;
use Database\Seeders\NumberSequenceSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseScreensTest extends TestCase
{
    use RefreshDatabase;

    private Product $yarn;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, FiscalYearSeeder::class, ChartOfAccountsSeeder::class, NumberSequenceSeeder::class]);
        $this->yarn = Product::create(['code' => 'Y1', 'name' => 'Cotton Yarn', 'unit_id' => Unit::create(['name' => 'Bundle'])->id, 'product_type' => 'raw_material', 'purchase_price' => 100, 'selling_price' => 0]);
        $this->supplier = Supplier::create(['code' => 'S001', 'name' => 'Al Zamin', 'is_active' => true]);
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
            'supplier_id' => $this->supplier->id, 'purchase_date' => '2026-10-05', 'supplier_ref' => 'BILL-001',
            'items' => [['product_id' => $this->yarn->id, 'qty' => '100', 'unit_cost' => '100']],
        ], $over);
    }

    private function postedPurchase(array $over = []): Purchase
    {
        $payload = $this->payload($over);
        $items = $payload['items'];
        unset($payload['items']);

        $service = app(PurchaseService::class);

        return $service->post($service->createDraft($payload, $items));
    }

    public function test_accountant_enters_and_posts_a_credit_purchase(): void
    {
        $this->actingAs($this->user('accountant'))->post('/purchases', $this->payload(['action' => 'post']))->assertSessionHasNoErrors();

        $p = Purchase::firstOrFail();
        $this->assertSame('posted', $p->status);
        $this->assertSame('10000.00', $this->supplier->ledgerBalance());
        $this->assertSame('100.000', $this->yarn->currentStock());
        $this->actingAs($this->user('accountant'))->get("/purchases/{$p->id}")->assertOk()->assertSee('PUR-0001')->assertSee('unpaid');
        $this->actingAs($this->user('accountant'))->get('/purchases')->assertOk()->assertSee('Al Zamin')->assertSee('10,000.00');
    }

    public function test_walk_in_cash_purchase_through_the_form(): void
    {
        $payload = $this->payload(['supplier_id' => '', 'supplier_ref' => '', 'paid_now' => '5000', 'paid_from_account_id' => Account::byCode(A::CASH)->id,
            'items' => [['product_id' => $this->yarn->id, 'qty' => '72', 'unit_cost' => '69.4444']], 'action' => 'post']);

        $this->actingAs($this->user('accountant'))->post('/purchases', $payload)->assertSessionHasNoErrors();

        $this->assertSame('paid', Purchase::firstOrFail()->paymentStatus());
        $this->actingAs($this->user('accountant'))->get('/purchases')->assertSee('Walk-in');
    }

    public function test_rule_violations_show_an_error_and_save_nothing(): void
    {
        $accountant = $this->user('accountant');

        $this->actingAs($accountant)->post('/purchases', $this->payload(['supplier_id' => '']))->assertSessionHasErrors('purchase');   // credit, nobody to owe
        $this->actingAs($accountant)->post('/purchases', $this->payload(['items' => []]))->assertSessionHasErrors('items');
        $this->actingAs($accountant)->post('/purchases', $this->payload(['items' => [['product_id' => $this->yarn->id, 'qty' => '1', 'unit_cost' => '-5']]]))->assertSessionHasErrors('items.0.unit_cost');
        $this->actingAs($accountant)->post('/purchases', $this->payload(['items' => [['product_id' => $this->yarn->id, 'qty' => '1', 'unit_cost' => '5', 'tax_rate' => '150']]]))->assertSessionHasErrors('items.0.tax_rate');
        $this->actingAs($accountant)->post('/purchases', $this->payload(['items' => [['product_id' => 9999, 'qty' => '1', 'unit_cost' => '5']]]))->assertSessionHasErrors('items.0.product_id');

        $this->supplier->update(['is_active' => false]);
        $this->actingAs($accountant)->post('/purchases', $this->payload())->assertSessionHasErrors('supplier_id');

        $this->assertSame(0, Purchase::count());
    }

    public function test_duplicate_supplier_invoice_is_rejected_on_the_form(): void
    {
        $accountant = $this->user('accountant');
        $this->actingAs($accountant)->post('/purchases', $this->payload(['action' => 'post']));

        $this->actingAs($accountant)->post('/purchases', $this->payload())->assertSessionHasErrors('purchase');
        $this->assertSame(1, Purchase::count());
    }

    public function test_draft_can_be_edited_then_posted_but_a_posted_purchase_cannot_be_edited(): void
    {
        $accountant = $this->user('accountant');
        $this->actingAs($accountant)->post('/purchases', $this->payload(['action' => 'draft']))->assertSessionHasNoErrors();
        $p = Purchase::firstOrFail();
        $this->assertSame('0.000', $this->yarn->currentStock());

        $this->actingAs($accountant)->get("/purchases/{$p->id}/edit")->assertOk();
        $this->actingAs($accountant)->put("/purchases/{$p->id}", $this->payload(['items' => [['product_id' => $this->yarn->id, 'qty' => '10', 'unit_cost' => '50']]]))->assertSessionHasNoErrors();
        $this->assertSame('500.00', $p->fresh()->total);

        $this->actingAs($accountant)->post("/purchases/{$p->id}/post")->assertSessionHas('success');
        $this->assertSame('10.000', $this->yarn->currentStock());

        $this->actingAs($accountant)->get("/purchases/{$p->id}/edit")->assertRedirect("/purchases/{$p->id}");
        $this->actingAs($accountant)->put("/purchases/{$p->id}", $this->payload())->assertSessionHasErrors('purchase');
    }

    public function test_only_drafts_can_be_deleted(): void
    {
        $accountant = $this->user('accountant');
        $this->actingAs($accountant)->post('/purchases', $this->payload(['action' => 'draft']));
        $draft = Purchase::firstOrFail();
        $this->actingAs($accountant)->delete("/purchases/{$draft->id}")->assertRedirect('/purchases');
        $this->assertNull(Purchase::find($draft->id));

        $this->actingAs($accountant)->post('/purchases', $this->payload(['supplier_ref' => 'BILL-9', 'action' => 'post']));
        $posted = Purchase::firstOrFail();
        $this->actingAs($accountant)->delete("/purchases/{$posted->id}")->assertSessionHas('error');
        $this->assertNotNull(Purchase::find($posted->id));
        $this->assertSame(1, $posted->items()->count());            // refusing the delete must not touch its lines
    }

    public function test_cancel_is_admin_only_needs_a_reason_and_reverses_everything(): void
    {
        $this->actingAs($this->user('accountant'))->post('/purchases', $this->payload(['action' => 'post']));
        $p = Purchase::firstOrFail();

        $this->actingAs($this->user('accountant'))->post("/purchases/{$p->id}/cancel", ['reason' => 'x'])->assertForbidden();

        $admin = $this->user('admin');
        $this->actingAs($admin)->post("/purchases/{$p->id}/cancel", ['reason' => ''])->assertSessionHasErrors('reason');
        $this->actingAs($admin)->post("/purchases/{$p->id}/cancel", ['reason' => 'entered twice'])->assertSessionHas('success');

        $this->assertSame('cancelled', $p->fresh()->status);
        $this->assertSame('0.000', $this->yarn->currentStock());
        $this->assertSame('0.00', $this->supplier->ledgerBalance());
    }

    public function test_purchase_return_flow_through_the_screens(): void
    {
        $accountant = $this->user('accountant');
        $this->actingAs($accountant)->post('/purchases', $this->payload(['action' => 'post']));
        $p = Purchase::firstOrFail();

        $this->actingAs($accountant)->get('/purchase-returns/create')->assertOk()->assertSee('PUR-0001');
        $this->actingAs($accountant)->get('/purchase-returns/create?purchase_id='.$p->id)->assertOk()->assertSee('Can still return');

        $this->actingAs($accountant)->post('/purchase-returns', [
            'purchase_id' => $p->id, 'return_date' => '2026-10-10', 'reason' => 'Damaged', 'qty' => [$this->yarn->id => '30'], 'action' => 'post',
        ])->assertSessionHasNoErrors();

        $r = PurchaseReturn::firstOrFail();
        $this->assertSame('posted', $r->status);
        $this->assertSame('7000.00', $this->supplier->ledgerBalance());
        $this->assertSame('70.000', $this->yarn->currentStock());
        $this->actingAs($accountant)->get('/purchase-returns')->assertOk()->assertSee('PRT-0001');
        $this->actingAs($accountant)->get("/purchase-returns/{$r->id}")->assertOk()->assertSee('Damaged');

        $this->actingAs($accountant)->post("/purchase-returns/{$r->id}/cancel", ['reason' => 'x'])->assertForbidden();
        $this->actingAs($this->user('admin'))->post("/purchase-returns/{$r->id}/cancel", ['reason' => 'supplier refused'])->assertSessionHas('success');
        $this->assertSame('10000.00', $this->supplier->ledgerBalance());
    }

    public function test_return_errors_are_shown_and_a_draft_return_is_kept_when_posting_fails(): void
    {
        $accountant = $this->user('accountant');
        $this->actingAs($accountant)->post('/purchases', $this->payload(['action' => 'post']));
        $p = Purchase::firstOrFail();

        $this->actingAs($accountant)->post('/purchase-returns', ['purchase_id' => $p->id, 'return_date' => '2026-10-10', 'qty' => [$this->yarn->id => '500']])
            ->assertSessionHasErrors('return');                                         // more than was bought
        $this->actingAs($accountant)->post('/purchase-returns', ['purchase_id' => $p->id, 'return_date' => '2026-10-10', 'qty' => [$this->yarn->id => '0']])
            ->assertSessionHasErrors('return');                                         // nothing entered
        $this->assertSame(0, PurchaseReturn::count());
    }

    public function test_guests_and_unauthorised_users_are_blocked(): void
    {
        $this->get('/purchases')->assertRedirect('/login');

        $viewer = User::factory()->create();            // no role at all
        $this->actingAs($viewer)->get('/purchases')->assertForbidden();
        $this->actingAs($viewer)->post('/purchases', $this->payload())->assertForbidden();
    }

    public function test_purchase_invoice_print_includes_supplier_tax_and_payment_details_and_is_audited(): void
    {
        $this->actingAs($this->user('accountant'));
        $this->supplier->update([
            'company' => 'Al Zamin Textiles',
            'contact_person' => 'Accounts Desk',
            'address' => 'Karachi, Pakistan',
            'phone' => '021-5550100',
            'email' => 'accounts@example.test',
            'ntn' => '1234567-8',
            'strn' => 'STR-100',
        ]);
        $purchase = $this->postedPurchase([
            'supplier_ref' => 'SUP-INV-42',
            'items' => [['product_id' => $this->yarn->id, 'qty' => '1', 'unit_cost' => '100.51', 'tax_rate' => '18']],
        ]);

        $this->get(route('purchases.invoice', $purchase))
            ->assertOk()
            ->assertSee('Fashion Industries INC')
            ->assertSee('Al Zamin Textiles')
            ->assertSee('Karachi, Pakistan')
            ->assertSee('SUP-INV-42')
            ->assertSee('18%')
            ->assertSeeInOrder(['Cotton Yarn', '101', '0', '18 (18%)', '119'])
            ->assertSee('Unpaid')
            ->assertSee('119')
            ->assertDontSee('100.51');
        $this->assertSame('118.60', $purchase->fresh()->total);

        $this->postJson(route('purchases.invoice.printed', $purchase))->assertOk()->assertJsonPath('times', 1);
        $this->postJson(route('purchases.invoice.printed', $purchase))->assertOk()->assertJsonPath('times', 2);
        $this->assertDatabaseHas('purchase_invoice_prints', ['purchase_id' => $purchase->id, 'print_count' => 2]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'purchase_invoice_printed',
            'auditable_type' => 'purchase',
            'auditable_id' => $purchase->id,
        ]);
    }

    public function test_cancelled_purchase_print_shows_cancellation_and_rejects_draft_documents(): void
    {
        $this->actingAs($this->user('accountant'));
        $purchase = $this->postedPurchase();
        app(PurchaseService::class)->cancel($purchase, 'Duplicate supplier bill');

        $this->get(route('purchases.invoice', $purchase))
            ->assertOk()
            ->assertSee('CANCELLED')
            ->assertSee('Duplicate supplier bill');
        $this->postJson(route('purchases.invoice.printed', $purchase))
            ->assertOk()
            ->assertJsonPath('times', 1);

        $draft = app(PurchaseService::class)->createDraft(
            ['supplier_id' => $this->supplier->id, 'purchase_date' => '2026-10-05', 'supplier_ref' => 'DRAFT-BILL'],
            [['product_id' => $this->yarn->id, 'qty' => '1', 'unit_cost' => '25']]
        );
        $this->get(route('purchases.invoice', $draft))->assertRedirect(route('purchases.show', $draft));
        $this->postJson(route('purchases.invoice.printed', $draft))
            ->assertUnprocessable()
            ->assertJsonPath('ok', false);
        $this->assertDatabaseCount('purchase_invoice_prints', 1);
    }

    public function test_purchase_invoice_viewing_does_not_grant_print_permission(): void
    {
        $purchase = $this->postedPurchase();
        $role = Role::create(['name' => 'purchase-reader', 'label' => 'Purchase Reader']);
        $role->permissions()->attach(Permission::where('name', 'purchases.view')->firstOrFail());
        $viewer = User::factory()->create();
        $viewer->roles()->attach($role);
        $this->actingAs($viewer);

        $this->get(route('purchases.invoice', $purchase))
            ->assertOk()
            ->assertSee('do not have permission to print it');
        $this->postJson(route('purchases.invoice.printed', $purchase))->assertForbidden();
        $this->assertDatabaseCount('purchase_invoice_prints', 0);
    }
}
