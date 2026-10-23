<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Role;
use App\Models\StockAdjustment;
use App\Models\Unit;
use App\Models\User;
use App\Services\Inventory\OpeningStockService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\FiscalYearSeeder;
use Database\Seeders\NumberSequenceSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductAndInventoryScreensTest extends TestCase
{
    use RefreshDatabase;

    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, FiscalYearSeeder::class, ChartOfAccountsSeeder::class, NumberSequenceSeeder::class]);
        $this->unit = Unit::create(['name' => 'Bundle']);
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->roles()->attach(Role::where('name', $role)->first());

        return $u;
    }

    private function payload(array $over = []): array
    {
        return array_merge(['code' => 'P001', 'name' => 'Grey Thread', 'unit_id' => $this->unit->id, 'product_type' => 'finished_good',
            'purchase_price' => '100', 'selling_price' => '120', 'min_stock' => '10', 'is_active' => '1'], $over);
    }

    public function test_accountant_can_create_and_edit_products_but_not_delete_them(): void
    {
        $accountant = $this->user('accountant');

        $this->actingAs($accountant)->post('/products', $this->payload())->assertRedirect('/products');
        $product = Product::where('code', 'P001')->firstOrFail();

        $this->actingAs($accountant)->put("/products/{$product->id}", $this->payload(['name' => 'Grey Thread 40/2']))->assertRedirect('/products');
        $this->assertSame('Grey Thread 40/2', $product->fresh()->name);

        $this->actingAs($accountant)->delete("/products/{$product->id}")->assertForbidden();
    }

    public function test_product_validation(): void
    {
        $admin = $this->user('admin');

        $this->actingAs($admin)->post('/products', $this->payload(['name' => '']))->assertSessionHasErrors('name');
        $this->actingAs($admin)->post('/products', $this->payload(['selling_price' => '-5']))->assertSessionHasErrors('selling_price');
        $this->actingAs($admin)->post('/products', $this->payload(['unit_id' => 999]))->assertSessionHasErrors('unit_id');

        $this->actingAs($admin)->post('/products', $this->payload());
        $this->actingAs($admin)->post('/products', $this->payload())->assertSessionHasErrors('code');   // duplicate code
    }

    public function test_code_is_generated_when_left_blank(): void
    {
        $this->actingAs($this->user('admin'))->post('/products', $this->payload(['code' => '']))->assertRedirect('/products');

        $this->assertMatchesRegularExpression('/^P\d{4}$/', Product::firstOrFail()->code);
    }

    public function test_opening_stock_entered_with_the_product_hits_ledger_and_inventory_account(): void
    {
        $this->actingAs($this->user('admin'))->post('/products', $this->payload(['opening_qty' => '204', 'opening_unit_cost' => '150', 'opening_date' => '2026-07-01']))
            ->assertSessionHasNoErrors();

        $product = Product::firstOrFail();
        $this->assertSame('204.000', $product->currentStock());
        $this->assertSame('30600.00', $product->stockValue());

        $this->actingAs($this->user('admin'))->get('/inventory')->assertOk()->assertSee('Reconciled');
    }

    public function test_opening_stock_in_a_date_without_a_fiscal_year_rolls_back_the_product(): void
    {
        $this->actingAs($this->user('admin'))
            ->post('/products', $this->payload(['opening_qty' => '5', 'opening_unit_cost' => '10', 'opening_date' => '2019-01-01']))
            ->assertSessionHasErrors('opening_qty');

        $this->assertSame(0, Product::count());
    }

    public function test_product_with_stock_history_cannot_be_deleted(): void
    {
        $product = Product::create(['code' => 'P9', 'name' => 'X', 'unit_id' => $this->unit->id, 'product_type' => 'trading', 'purchase_price' => 0, 'selling_price' => 0]);
        app(OpeningStockService::class)->record($product, 1, 10, '2026-10-01');

        $this->actingAs($this->user('admin'))->delete("/products/{$product->id}")->assertSessionHas('error');
        $this->assertNotNull($product->fresh());
    }

    public function test_image_upload_is_validated_and_stored_under_a_random_name(): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD extension not available.');
        }
        Storage::fake('public');
        $admin = $this->user('admin');

        $this->actingAs($admin)->post('/products', $this->payload(['image' => UploadedFile::fake()->create('evil.php', 10, 'application/x-php')]))
            ->assertSessionHasErrors('image');

        $this->actingAs($admin)->post('/products', $this->payload(['image' => UploadedFile::fake()->image('thread.jpg', 200, 200)]))->assertSessionHasNoErrors();
        $path = Product::firstOrFail()->image_path;
        $this->assertStringStartsWith('products/', $path);
        $this->assertNotSame('thread.jpg', basename($path));
        Storage::disk('public')->assertExists($path);
    }

    public function test_categories_and_units_permissions(): void
    {
        $this->actingAs($this->user('accountant'))->post('/categories', ['name' => 'Yarn'])->assertSessionHasNoErrors();
        $this->assertTrue(Category::where('name', 'Yarn')->exists());

        $category = Category::where('name', 'Yarn')->first();
        $this->actingAs($this->user('accountant'))->delete("/categories/{$category->id}")->assertForbidden();   // delete is admin-only
    }

    public function test_accountant_saves_a_draft_adjustment_but_cannot_post_it_admin_can(): void
    {
        $product = Product::create(['code' => 'P1', 'name' => 'Thread', 'unit_id' => $this->unit->id, 'product_type' => 'trading', 'purchase_price' => 0, 'selling_price' => 0]);
        app(OpeningStockService::class)->record($product, 20, 10, '2026-10-01');
        $payload = ['adjustment_date' => '2026-10-05', 'reason' => 'Count', 'items' => [['product_id' => $product->id, 'direction' => 'out', 'qty' => '2']]];
        $accountant = $this->user('accountant');

        $this->actingAs($accountant)->post('/stock-adjustments', $payload + ['action' => 'draft'])->assertSessionHasNoErrors();
        $adj = StockAdjustment::firstOrFail();
        $this->assertSame('draft', $adj->status);
        $this->assertSame('20.000', $product->currentStock());

        $this->actingAs($accountant)->post("/stock-adjustments/{$adj->id}/post")->assertForbidden();
        $this->actingAs($accountant)->post('/stock-adjustments', $payload + ['action' => 'post'])->assertForbidden();
        $this->assertSame(1, StockAdjustment::count());          // the forbidden "save & post" saved nothing

        $this->actingAs($this->user('admin'))->post("/stock-adjustments/{$adj->id}/post")->assertSessionHas('success');
        $this->assertSame('18.000', $product->currentStock());
    }

    public function test_deleting_a_posted_adjustment_is_refused_and_keeps_its_items(): void
    {
        $product = Product::create(['code' => 'P1', 'name' => 'Thread', 'unit_id' => $this->unit->id, 'product_type' => 'trading', 'purchase_price' => 0, 'selling_price' => 0]);
        app(OpeningStockService::class)->record($product, 20, 10, '2026-10-01');
        $service = app(\App\Services\Inventory\StockAdjustmentService::class);
        $adj = $service->post($service->createDraft(['adjustment_date' => '2026-10-05', 'reason' => 'x'], [['product_id' => $product->id, 'direction' => 'out', 'qty' => 2]]));

        $this->actingAs($this->user('admin'))->delete("/stock-adjustments/{$adj->id}")->assertSessionHas('error');

        $this->assertNotNull(StockAdjustment::find($adj->id));
        $this->assertSame(1, $adj->items()->count());
    }

    public function test_stock_in_requires_a_cost_on_the_form(): void
    {
        $product = Product::create(['code' => 'P1', 'name' => 'Thread', 'unit_id' => $this->unit->id, 'product_type' => 'trading', 'purchase_price' => 0, 'selling_price' => 0]);

        $this->actingAs($this->user('admin'))->post('/stock-adjustments', [
            'adjustment_date' => '2026-10-05', 'reason' => 'x', 'items' => [['product_id' => $product->id, 'direction' => 'in', 'qty' => '3']],
        ])->assertSessionHasErrors('items.0.unit_cost');
    }

    public function test_inventory_ledger_screen_shows_movements(): void
    {
        $product = Product::create(['code' => 'P1', 'name' => 'Thread', 'unit_id' => $this->unit->id, 'product_type' => 'trading', 'purchase_price' => 0, 'selling_price' => 0]);
        app(OpeningStockService::class)->record($product, 20, 10, '2026-10-01');

        $this->actingAs($this->user('accountant'))->get('/inventory/ledger?product_id=' . $product->id)->assertOk()->assertSee('Opening stock entered');
    }
}
