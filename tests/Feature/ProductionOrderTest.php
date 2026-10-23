<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\JournalEntryLine;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\Inventory\OpeningStockService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\FiscalYearSeeder;
use Database\Seeders\NumberSequenceSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductionOrderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, FiscalYearSeeder::class, ChartOfAccountsSeeder::class, NumberSequenceSeeder::class]);
    }

    public function test_posts_consumed_yarn_and_paid_processing_fee_into_finished_thread_inventory(): void
    {
        $admin = $this->userWithRole();
        ['yarn' => $yarn, 'thread' => $thread] = $this->products();
        app(OpeningStockService::class)->record($yarn, 10, 100, '2026-10-01');

        $response = $this->actingAs($admin)->post('/production-orders', $this->payload($yarn, $thread));
        $order = ProductionOrder::firstOrFail();

        $response->assertRedirect(route('production-orders.show', $order));
        $this->assertSame('posted', $order->fresh()->status);
        $this->assertSame('8.000', $yarn->fresh()->currentStock());
        $this->assertSame('19.000', $thread->fresh()->currentStock());
        $this->assertSame('250.00', $thread->fresh()->stockValue());
        $this->assertDatabaseHas('journal_entry_lines', [
            'journal_entry_id' => $order->journal_entry_id,
            'account_id' => Account::byCode('1010')->id,
            'credit' => '50.00',
        ]);
        $this->actingAs($admin)->get(route('production-orders.show', $order))
            ->assertOk()
            ->assertSee('Finished inventory cost')
            ->assertSee('250.00');
    }

    public function test_records_an_unpaid_processing_fee_against_the_processor_supplier(): void
    {
        $admin = $this->userWithRole();
        ['yarn' => $yarn, 'thread' => $thread] = $this->products();
        $processor = Supplier::create(['code' => 'PROC-01', 'name' => 'Thread processor']);
        app(OpeningStockService::class)->record($yarn, 10, 100, '2026-10-01');

        $response = $this->actingAs($admin)->post('/production-orders', $this->payload($yarn, $thread, [
            'processor_supplier_id' => $processor->id,
            'paid_from_account_id' => '',
        ]));
        $order = ProductionOrder::firstOrFail();

        $response->assertRedirect(route('production-orders.show', $order));
        $this->assertDatabaseHas('journal_entry_lines', [
            'journal_entry_id' => $order->journal_entry_id,
            'account_id' => Account::byCode('2000')->id,
            'party_type' => $processor->getMorphClass(),
            'party_id' => $processor->id,
            'credit' => '50.00',
        ]);
        $this->assertSame('250.00', $thread->fresh()->stockValue());
    }

    public function test_rejects_a_missing_processor_or_a_transaction_that_does_not_convert_products(): void
    {
        $admin = $this->userWithRole();
        ['yarn' => $yarn, 'thread' => $thread] = $this->products();

        $this->actingAs($admin)->from('/production-orders/create')->post('/production-orders', $this->payload($yarn, $thread, [
            'processor_supplier_id' => '',
            'paid_from_account_id' => '',
        ]))->assertSessionHasErrors('processor_supplier_id');

        $this->actingAs($admin)->from('/production-orders/create')->post('/production-orders', $this->payload($yarn, $thread, [
            'output_product_id' => $yarn->id,
        ]))->assertSessionHasErrors('inputs.0.product_id');

        $this->assertSame(0, ProductionOrder::count());
    }

    public function test_allocates_the_complete_cost_pool_by_estimated_output_cost_and_posts_balanced_inventory(): void
    {
        $admin = $this->userWithRole();
        ['yarn' => $yarn, 'thread' => $thread] = $this->products();
        $fabric = Product::create([
            'code' => 'FABRIC-01', 'name' => 'Cotton fabric', 'unit_id' => $thread->unit_id,
            'product_type' => 'finished_good', 'purchase_price' => 0, 'selling_price' => 0,
        ]);
        app(OpeningStockService::class)->record($yarn, 10, 100, '2026-10-01');
        $payload = $this->payload($yarn, $thread, [
            'outputs' => [
                ['product_id' => $thread->id, 'qty' => '10', 'allocation_basis' => '100.00'],
                ['product_id' => $fabric->id, 'qty' => '20', 'allocation_basis' => '200.00'],
            ],
        ]);

        $this->actingAs($admin)->post('/production-orders', $payload)->assertRedirect();
        $order = ProductionOrder::with('outputs')->firstOrFail();

        $this->assertSame(['8.3330', '8.3335'], $order->outputs->pluck('unit_cost')->all());
        $this->assertSame('83.33', $thread->fresh()->stockValue());
        $this->assertSame('166.67', $fabric->fresh()->stockValue());
        $this->assertSame(
            '250.00',
            number_format((float) JournalEntryLine::where('journal_entry_id', $order->journal_entry_id)
                ->where('account_id', Account::byCode('1200')->id)
                ->sum('debit'), 2, '.', '')
        );
        $this->assertSame(
            (string) JournalEntryLine::sum('debit'),
            (string) JournalEntryLine::sum('credit')
        );
    }

    public function test_requires_an_estimated_cost_basis_for_each_of_multiple_outputs(): void
    {
        $admin = $this->userWithRole();
        ['yarn' => $yarn, 'thread' => $thread] = $this->products();
        $fabric = Product::create([
            'code' => 'FABRIC-01', 'name' => 'Cotton fabric', 'unit_id' => $thread->unit_id,
            'product_type' => 'finished_good', 'purchase_price' => 0, 'selling_price' => 0,
        ]);

        $this->actingAs($admin)->from('/production-orders/create')->post('/production-orders', $this->payload($yarn, $thread, [
            'outputs' => [
                ['product_id' => $thread->id, 'qty' => '10', 'allocation_basis' => '100.00'],
                ['product_id' => $fabric->id, 'qty' => '20', 'allocation_basis' => ''],
            ],
        ]))->assertSessionHasErrors('outputs.1.allocation_basis');

        $this->assertSame(0, ProductionOrder::count());
    }

    public function test_keeps_an_order_as_a_draft_when_yarn_stock_is_insufficient(): void
    {
        $admin = $this->userWithRole();
        ['yarn' => $yarn, 'thread' => $thread] = $this->products();
        app(OpeningStockService::class)->record($yarn, 1, 100, '2026-10-01');
        $payload = $this->payload($yarn, $thread, ['action' => 'draft']);

        $this->actingAs($admin)->post('/production-orders', $payload)->assertSessionHasNoErrors();
        $order = ProductionOrder::firstOrFail();

        $this->actingAs($admin)->post("/production-orders/{$order->id}/post")->assertSessionHas('error');

        $this->assertSame('draft', $order->fresh()->status);
        $this->assertSame('1.000', $yarn->fresh()->currentStock());
        $this->assertSame('0.000', $thread->fresh()->currentStock());
        $this->assertNull($order->fresh()->journal_entry_id);
    }

    public function test_reverses_yarn_thread_and_fee_payment_when_a_posted_production_transaction_is_cancelled(): void
    {
        $admin = $this->userWithRole();
        ['yarn' => $yarn, 'thread' => $thread] = $this->products();
        app(OpeningStockService::class)->record($yarn, 10, 100, '2026-10-01');
        $this->actingAs($admin)->post('/production-orders', $this->payload($yarn, $thread));
        $order = ProductionOrder::firstOrFail();

        $response = $this->actingAs($admin)->post("/production-orders/{$order->id}/cancel", ['reason' => 'Incorrect batch']);

        $response->assertSessionHas('success');
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame('10.000', $yarn->fresh()->currentStock());
        $this->assertSame('1000.00', $yarn->fresh()->stockValue());
        $this->assertSame('0.000', $thread->fresh()->currentStock());
        $this->assertNotNull($order->fresh()->reversal_entry_id);
    }

    public function test_requires_authentication_to_open_production_transactions(): void
    {
        $this->get('/production-orders')->assertRedirect(route('login'));
    }

    public function test_admin_can_open_the_production_form_and_transaction_list(): void
    {
        $admin = $this->userWithRole();

        $this->actingAs($admin)->get('/production-orders/create')->assertOk()->assertSee('Processing fee');
        $this->actingAs($admin)->get('/production-orders')->assertOk()->assertSee('No production transactions.');
    }

    private function userWithRole(string $role = 'admin'): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('name', $role)->firstOrFail());

        return $user;
    }

    /**
     * @return array{yarn: Product, thread: Product}
     */
    private function products(): array
    {
        $unit = Unit::create(['name' => 'Bundle']);

        return [
            'yarn' => Product::create([
                'code' => 'YARN-01', 'name' => 'Yarn bundle', 'unit_id' => $unit->id,
                'product_type' => 'trading', 'purchase_price' => 0, 'selling_price' => 0,
            ]),
            'thread' => Product::create([
                'code' => 'THREAD-01', 'name' => 'Cotton thread', 'unit_id' => $unit->id,
                'product_type' => 'finished_good', 'purchase_price' => 0, 'selling_price' => 0,
            ]),
        ];
    }

    private function payload(Product $yarn, Product $thread, array $overrides = []): array
    {
        return array_merge([
            'order_date' => '2026-10-05',
            'inputs' => [['product_id' => $yarn->id, 'qty' => '2']],
            'output_product_id' => $thread->id,
            'output_qty' => '19',
            'processing_fee' => '50.00',
            'processor_supplier_id' => null,
            'paid_from_account_id' => Account::byCode('1010')->id,
            'action' => 'post',
        ], $overrides);
    }
}
