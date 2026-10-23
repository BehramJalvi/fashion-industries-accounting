<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\NumberSequence;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\Unit;
use App\Models\User;
use App\Services\Inventory\OpeningStockService;
use App\Services\Sales\SaleService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\FiscalYearSeeder;
use Database\Seeders\NumberSequenceSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SettingsScreensTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, FiscalYearSeeder::class, ChartOfAccountsSeeder::class, NumberSequenceSeeder::class, SettingsSeeder::class]);
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->roles()->attach(Role::where('name', $role)->first());

        return $u;
    }

    /** A complete, valid form body (the screen always posts every field). */
    private function form(array $over = []): array
    {
        return array_merge([
            'company_name' => 'Fashion Industries INC', 'company_tagline' => 'Manufacturer of Sewing threads, Accessories & Trims',
            'company_address' => 'P-11 Millat Industrial Estate, Millat Road, Faisalabad, Pakistan', 'company_phone' => '', 'company_email' => '',
            'company_ntn' => '8544806-0', 'company_strn' => '3277876303861',
            'invoice_title' => 'SALES TAX INVOICE', 'invoice_footer' => 'This invoice is computer generated; signature and stamp are not required.',
            'invoice_min_rows' => '10', 'invoice_show_payment' => '1',
            'default_sales_tax_rate' => '18', 'allow_negative_stock' => '0',
            'sale_prefix' => '', 'sale_next_number' => '258', 'sale_padding' => '1',
        ], $over);
    }

    private function postASale(): Sale
    {
        $p = Product::create(['code' => 'P1', 'name' => 'Grey Thread', 'unit_id' => Unit::create(['name' => 'Bundle'])->id, 'product_type' => 'finished_good', 'purchase_price' => 0, 'selling_price' => 100]);
        app(OpeningStockService::class)->record($p, 1000, 60, '2026-10-01');
        $c = Customer::create(['code' => 'C001', 'name' => 'Multan Fabrics PVT Limited']);
        $sales = app(SaleService::class);

        return $sales->post($sales->createDraft(['customer_id' => $c->id, 'sale_date' => '2026-10-05'], [['product_id' => $p->id, 'qty' => 1, 'unit_price' => 100]]));
    }

    public function test_only_an_administrator_can_see_and_change_settings(): void
    {
        $accountant = $this->user('accountant');
        $this->actingAs($accountant)->get('/settings')->assertForbidden();
        $this->actingAs($accountant)->put('/settings', $this->form())->assertForbidden();

        $this->actingAs($this->user('admin'))->get('/settings')->assertOk()->assertSee('Invoice numbering')->assertSee('8544806-0');
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get('/settings')->assertRedirect('/login');
        $this->put('/settings', $this->form())->assertRedirect('/login');
    }

    public function test_changes_are_saved_audited_and_used_across_the_app(): void
    {
        $admin = $this->user('admin');

        $this->actingAs($admin)->put('/settings', $this->form(['company_name' => 'Fashion Industries Pvt Ltd', 'company_phone' => '041-1234567', 'company_email' => 'info@example.com',
            'invoice_title' => 'TAX INVOICE', 'invoice_min_rows' => '12', 'invoice_show_payment' => '0', 'allow_negative_stock' => '1', 'default_sales_tax_rate' => '17']))
            ->assertRedirect('/settings')->assertSessionHasNoErrors();

        $this->assertSame('Fashion Industries Pvt Ltd', Setting::get('company_name'));
        $this->assertSame('041-1234567', Setting::get('company_phone'));
        $this->assertSame('TAX INVOICE', Setting::get('invoice_title'));
        $this->assertSame(12, Setting::get('invoice_min_rows'));
        $this->assertFalse(Setting::get('invoice_show_payment'));
        $this->assertTrue(Setting::get('allow_negative_stock'));
        $this->assertEquals(17, Setting::get('default_sales_tax_rate'));

        $this->assertDatabaseHas('audit_logs', ['event' => 'updated', 'auditable_type' => 'setting', 'user_id' => $admin->id]);
        $this->actingAs($admin)->get('/dashboard')->assertOk()->assertSee('Fashion Industries Pvt Ltd');          // brand in the sidebar
        $this->actingAs($admin)->get('/customers/create')->assertOk()->assertSee('value="17.00"', false);          // new customers default to the new tax rate
    }

    public function test_checkboxes_turn_off_when_unticked(): void
    {
        $admin = $this->user('admin');
        $this->actingAs($admin)->put('/settings', $this->form(['allow_negative_stock' => '1']));
        $this->assertTrue(Setting::get('allow_negative_stock'));

        $this->actingAs($admin)->put('/settings', $this->form(['allow_negative_stock' => '0']));
        $this->assertFalse(Setting::get('allow_negative_stock'));
    }

    public function test_validation(): void
    {
        $admin = $this->user('admin');

        $this->actingAs($admin)->put('/settings', $this->form(['company_name' => '']))->assertSessionHasErrors('company_name');
        $this->actingAs($admin)->put('/settings', $this->form(['company_email' => 'not-an-email']))->assertSessionHasErrors('company_email');
        $this->actingAs($admin)->put('/settings', $this->form(['default_sales_tax_rate' => '150']))->assertSessionHasErrors('default_sales_tax_rate');
        $this->actingAs($admin)->put('/settings', $this->form(['invoice_min_rows' => '100']))->assertSessionHasErrors('invoice_min_rows');
        $this->actingAs($admin)->put('/settings', $this->form(['sale_padding' => '0']))->assertSessionHasErrors('sale_padding');
        $this->actingAs($admin)->put('/settings', $this->form(['sale_prefix' => 'IN V<script>']))->assertSessionHasErrors('sale_prefix');
        $this->actingAs($admin)->put('/settings', $this->form(['sale_next_number' => '0']))->assertSessionHasErrors('sale_next_number');

        $this->assertSame('Fashion Industries INC', Setting::get('company_name'));    // nothing changed
    }

    public function test_next_invoice_number_can_move_forward_but_never_collide(): void
    {
        $admin = $this->user('admin');
        $first = $this->postASale();
        $this->assertSame('258', $first->invoice_no);

        // 258 is used: the next number must be higher
        $this->actingAs($admin)->put('/settings', $this->form(['sale_next_number' => '258']))->assertSessionHasErrors('sale_next_number');
        $this->actingAs($admin)->put('/settings', $this->form(['sale_next_number' => '100']))->assertSessionHasErrors('sale_next_number');
        $this->assertSame(259, NumberSequence::where('doc_type', 'sale')->value('next_number'));

        // moving forward is fine, and the next posted invoice takes it
        $this->actingAs($admin)->put('/settings', $this->form(['sale_next_number' => '300']))->assertSessionHasNoErrors();
        $this->assertSame(300, NumberSequence::where('doc_type', 'sale')->value('next_number'));
        $this->assertDatabaseHas('audit_logs', ['event' => 'numbering_changed']);

        $sales = app(SaleService::class);
        $second = $sales->post($sales->createDraft(['customer_id' => Customer::firstOrFail()->id, 'sale_date' => '2026-10-06'], [['product_id' => Product::firstOrFail()->id, 'qty' => 1, 'unit_price' => 100]]));
        $this->assertSame('300', $second->invoice_no);
    }

    public function test_prefix_and_padding_change_the_number_format(): void
    {
        $this->actingAs($this->user('admin'))->put('/settings', $this->form(['sale_prefix' => 'INV-', 'sale_next_number' => '1', 'sale_padding' => '4']))->assertSessionHasNoErrors();

        $sale = $this->postASale();

        $this->assertSame('INV-0001', $sale->invoice_no);
    }

    public function test_logo_upload_is_validated_stored_under_a_random_name_and_can_be_removed(): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD extension not available.');
        }
        Storage::fake('public');
        $admin = $this->user('admin');

        $this->actingAs($admin)->put('/settings', $this->form(['logo' => UploadedFile::fake()->create('logo.php', 10, 'application/x-php')]))->assertSessionHasErrors('logo');
        $this->actingAs($admin)->put('/settings', $this->form(['logo' => UploadedFile::fake()->create('logo.svg', 10, 'image/svg+xml')]))->assertSessionHasErrors('logo');
        $this->assertNull(Setting::get('company_logo_path'));

        $this->actingAs($admin)->put('/settings', $this->form(['logo' => UploadedFile::fake()->image('my-logo.png', 300, 100)]))->assertSessionHasNoErrors();
        $path = Setting::get('company_logo_path');
        $this->assertStringStartsWith('branding/', $path);
        $this->assertNotSame('my-logo.png', basename($path));
        Storage::disk('public')->assertExists($path);

        $this->actingAs($admin)->put('/settings', $this->form(['logo' => UploadedFile::fake()->image('new.jpg', 300, 100)]))->assertSessionHasNoErrors();
        Storage::disk('public')->assertMissing($path);                              // the old file is deleted when replaced
        $newPath = Setting::get('company_logo_path');
        Storage::disk('public')->assertExists($newPath);

        $this->actingAs($admin)->put('/settings', $this->form(['remove_logo' => '1']))->assertSessionHasNoErrors();
        Storage::disk('public')->assertMissing($newPath);
        $this->assertNull(Setting::get('company_logo_path'));
    }
}
