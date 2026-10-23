<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Invoice;
use App\Models\NumberSequence;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\Unit;
use App\Models\User;
use App\Services\Inventory\OpeningStockService;
use App\Services\Sales\SaleService;
use App\Support\AccountCodes as A;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\FiscalYearSeeder;
use Database\Seeders\NumberSequenceSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoicePrintTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;
    /** @var array<string, Product> */
    private array $products = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, FiscalYearSeeder::class, ChartOfAccountsSeeder::class, NumberSequenceSeeder::class, SettingsSeeder::class]);

        // The sample invoice is dated 29/03/2025, so give it a fiscal year, and make it number 257 as in the PDF.
        FiscalYear::create(['name' => '2024-25', 'start_date' => '2024-07-01', 'end_date' => '2025-06-30']);
        NumberSequence::where('doc_type', 'sale')->update(['next_number' => 257]);

        $unit = Unit::create(['name' => 'Bundle']);
        foreach (['Harbarver Grey Thread', 'Limpet Shell Thread', 'Green Thread', 'Grey Thread', 'Yellow Thread'] as $i => $name) {
            $p = Product::create(['code' => 'P00' . ($i + 1), 'name' => $name, 'unit_id' => $unit->id, 'product_type' => 'finished_good', 'purchase_price' => 0, 'selling_price' => 100]);
            app(OpeningStockService::class)->record($p, 1000, 60, '2025-03-01');
            $this->products[$name] = $p;
        }

        $this->customer = Customer::create([
            'code' => 'C001', 'name' => 'Multan Fabrics PVT Limited', 'address' => '17-A Export Zone Ansar Colony Multan',
            'ntn' => '1290353-1', 'strn' => '04-90-9999-247-82',
        ]);
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->roles()->attach(Role::where('name', $role)->first());

        return $u;
    }

    /** The five lines of invoice 257. */
    private function sampleInvoice(array $header = [], array $itemExtra = []): Sale
    {
        $items = [];
        foreach (['Harbarver Grey Thread' => 237, 'Limpet Shell Thread' => 228, 'Green Thread' => 42, 'Grey Thread' => 42, 'Yellow Thread' => 42] as $name => $qty) {
            $items[] = array_merge(['product_id' => $this->products[$name]->id, 'description' => $name, 'qty' => $qty, 'unit_price' => 100], $itemExtra);
        }
        $sales = app(SaleService::class);

        return $sales->post($sales->createDraft(array_merge(['customer_id' => $this->customer->id, 'sale_date' => '2025-03-29', 'gate_pass_no' => '553'], $header), $items));
    }

    public function test_the_printed_invoice_reproduces_the_sample_layout_and_figures(): void
    {
        $sale = $this->sampleInvoice();
        $this->assertSame('257', $sale->invoice_no);

        $page = $this->actingAs($this->user('accountant'))->get("/sales/{$sale->id}/invoice")->assertOk();

        $page->assertSee('ORIGINAL:')->assertSee('SALES TAX INVOICE')->assertSee('INVOICE NO :')->assertSee('NTN NO.')->assertSee('SALES TAX REG NO.')
            ->assertSee('8544806-0')->assertSee('3277876303861')->assertSee('DATE : 29/03/2025')->assertSee('OGP')->assertSee('553')
            ->assertSee("BUYER'S NAME :", false)->assertSee('Multan Fabrics PVT Limited')->assertSee('17-A Export Zone Ansar Colony Multan')
            ->assertSee('1290353-1')->assertSee('04-90-9999-247-82')
            ->assertSee('Manufacturer of Sewing threads, Accessories & Trims')->assertSee('P-11 Millat Industrial Estate')
            ->assertSee('Sr #')->assertSee('Unit Rate')->assertSee('Value Ex-Sales Tax')->assertSee('Sales Tax Payable')->assertSee('Value Inc. Sales Tax')
            ->assertSee('This invoice is computer generated; signature and stamp are not required.');

        // the five lines, with the amounts printed on the sample
        $page->assertSeeInOrder(['Harbarver Grey Thread', '237', '100', '18%', '23,700', '4,266', '27,966']);
        $page->assertSeeInOrder(['Limpet Shell Thread', '228', '100', '18%', '22,800', '4,104', '26,904']);
        $page->assertSeeInOrder(['Yellow Thread', '42', '100', '18%', '4,200', '756', '4,956']);
        $page->assertSeeInOrder(['TOTAL', '591', '59,100', '10,638', '69,738']);

        $html = $page->getContent();
        $this->assertSame(5, substr_count($html, 'class="blank-row"'));          // padded to ten rows, as on the sample
        $this->assertStringNotContainsString('<th style="width:9%">Discount</th>', $html);   // no discount column when nothing is discounted
        $this->assertStringNotContainsString('class="pay"', $html);                          // credit sale: no Received / Balance block
    }

    public function test_printed_money_is_rounded_to_whole_units_without_changing_saved_amounts(): void
    {
        $sale = $this->sampleInvoice([], ['unit_price' => '100.51']);
        $page = $this->actingAs($this->user('accountant'))->get("/sales/{$sale->id}/invoice");

        $page->assertSeeInOrder(['Harbarver Grey Thread', '237', '101', '18%', '23,821', '4,288', '28,109'])
            ->assertDontSee('100.51');
        $this->assertSame('100.5100', $sale->items()->firstOrFail()->unit_price);
    }

    public function test_a_discount_column_appears_only_when_something_is_discounted(): void
    {
        $sale = $this->sampleInvoice([], ['discount' => '100']);

        $html = $this->actingAs($this->user('accountant'))->get("/sales/{$sale->id}/invoice")->assertOk()->getContent();

        $this->assertStringContainsString('<th style="width:9%">Discount</th>', $html);
    }

    public function test_received_and_balance_show_only_when_something_was_paid_at_the_sale(): void
    {
        $sale = $this->sampleInvoice(['received_now' => '20000', 'received_into_account_id' => Account::byCode(A::CASH)->id]);
        $accountant = $this->user('accountant');

        $this->actingAs($accountant)->get("/sales/{$sale->id}/invoice")->assertOk()->assertSee('class="pay"', false)->assertSee('Received')->assertSee('20,000')->assertSee('Balance')->assertSee('49,738');

        Setting::set('invoice_show_payment', false);
        $this->actingAs($accountant)->get("/sales/{$sale->id}/invoice")->assertOk()->assertDontSee('class="pay"', false);
    }

    public function test_company_logo_replaces_the_name_in_the_header(): void
    {
        $sale = $this->sampleInvoice();
        Setting::set('company_logo_path', 'branding/logo.png');

        $this->actingAs($this->user('accountant'))->get("http://127.0.0.1:8000/sales/{$sale->id}/invoice")->assertOk()
            ->assertSee('http://127.0.0.1:8000/storage/branding/logo.png', false)
            ->assertSee('loading="eager" decoding="sync"', false)
            ->assertSee('await logo.decode();', false)
            ->assertDontSee('<div class="name">', false);
    }

    public function test_original_is_printed_once_then_duplicates_and_only_an_admin_can_reprint_the_original(): void
    {
        $sale = $this->sampleInvoice();
        $accountant = $this->user('accountant');
        $admin = $this->user('admin');

        $this->actingAs($accountant)->get("/sales/{$sale->id}/invoice")->assertOk()->assertSee('ORIGINAL:');
        $this->assertSame(0, Invoice::count());                                  // looking at the page logs nothing

        $this->actingAs($accountant)->postJson("/sales/{$sale->id}/invoice/printed", ['copy' => 'original'])->assertOk()->assertJson(['ok' => true, 'copy' => 'original', 'times' => 1]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'invoice_printed', 'auditable_type' => 'sale', 'auditable_id' => $sale->id]);

        $this->actingAs($accountant)->get("/sales/{$sale->id}/invoice")->assertOk()->assertSee('DUPLICATE:');          // next default is the duplicate
        $this->actingAs($accountant)->get("/sales/{$sale->id}/invoice?copy=original")->assertRedirect("/sales/{$sale->id}")->assertSessionHas('error');
        $this->actingAs($accountant)->postJson("/sales/{$sale->id}/invoice/printed", ['copy' => 'original'])->assertStatus(422)->assertJson(['ok' => false]);

        $this->actingAs($admin)->get("/sales/{$sale->id}/invoice?copy=original")->assertOk()->assertSee('ORIGINAL:');
        $this->actingAs($admin)->postJson("/sales/{$sale->id}/invoice/printed", ['copy' => 'original'])->assertOk()->assertJson(['times' => 2]);

        $this->actingAs($accountant)->postJson("/sales/{$sale->id}/invoice/printed", ['copy' => 'duplicate'])->assertOk()->assertJson(['times' => 1]);
        $this->actingAs($accountant)->postJson("/sales/{$sale->id}/invoice/printed", ['copy' => 'duplicate'])->assertOk()->assertJson(['times' => 2]);

        $this->assertSame(2, Invoice::count());                                  // one row per copy type, with a counter
        $this->assertSame(2, Invoice::where('copy_type', 'original')->value('print_count'));
    }

    public function test_cancelled_invoices_print_only_the_cancelled_copy(): void
    {
        $sale = $this->sampleInvoice();
        $accountant = $this->user('accountant');

        // a posted invoice has no cancelled copy
        $this->actingAs($accountant)->get("/sales/{$sale->id}/invoice?copy=cancelled")->assertRedirect("/sales/{$sale->id}")->assertSessionHas('error');
        $this->actingAs($accountant)->postJson("/sales/{$sale->id}/invoice/printed", ['copy' => 'cancelled'])->assertStatus(422);

        app(SaleService::class)->cancel($sale, 'wrong customer');

        $this->actingAs($accountant)->get("/sales/{$sale->id}/invoice")->assertOk()->assertSee('CANCELLED:')->assertSee('class="watermark"', false)->assertSee('wrong customer')->assertSee('257');
        $this->actingAs($accountant)->get("/sales/{$sale->id}/invoice?copy=original")->assertRedirect("/sales/{$sale->id}")->assertSessionHas('error');
        $this->actingAs($accountant)->get("/sales/{$sale->id}/invoice?copy=duplicate")->assertRedirect("/sales/{$sale->id}")->assertSessionHas('error');
        $this->actingAs($accountant)->postJson("/sales/{$sale->id}/invoice/printed", ['copy' => 'cancelled'])->assertOk()->assertJson(['copy' => 'cancelled', 'times' => 1]);
    }

    public function test_a_draft_cannot_be_printed(): void
    {
        $sales = app(SaleService::class);
        $draft = $sales->createDraft(['customer_id' => $this->customer->id, 'sale_date' => '2025-03-29'], [['product_id' => $this->products['Green Thread']->id, 'qty' => 1, 'unit_price' => 100]]);
        $accountant = $this->user('accountant');

        $this->actingAs($accountant)->get("/sales/{$draft->id}/invoice")->assertRedirect("/sales/{$draft->id}")->assertSessionHas('error');
        $this->actingAs($accountant)->postJson("/sales/{$draft->id}/invoice/printed", ['copy' => 'original'])->assertStatus(422);
        $this->assertSame(0, Invoice::count());
    }

    public function test_unknown_copy_is_rejected(): void
    {
        $sale = $this->sampleInvoice();
        $accountant = $this->user('accountant');

        $this->actingAs($accountant)->get("/sales/{$sale->id}/invoice?copy=bogus")->assertRedirect("/sales/{$sale->id}")->assertSessionHas('error');
        $this->actingAs($accountant)->postJson("/sales/{$sale->id}/invoice/printed", ['copy' => 'bogus'])->assertStatus(422);
        $this->actingAs($accountant)->postJson("/sales/{$sale->id}/invoice/printed", [])->assertStatus(422);
    }

    public function test_viewing_and_printing_need_different_permissions(): void
    {
        $sale = $this->sampleInvoice();

        $nobody = User::factory()->create();
        $this->actingAs($nobody)->get("/sales/{$sale->id}/invoice")->assertForbidden();

        $role = Role::create(['name' => 'viewer', 'label' => 'Viewer']);
        $role->permissions()->attach(Permission::where('name', 'invoices.view')->first());
        $viewer = User::factory()->create();
        $viewer->roles()->attach($role);

        $this->actingAs($viewer)->get("/sales/{$sale->id}/invoice")->assertOk()->assertDontSee('id="printBtn"', false)->assertSee('View only');
        $this->actingAs($viewer)->postJson("/sales/{$sale->id}/invoice/printed", ['copy' => 'original'])->assertForbidden();
        $this->assertSame(0, Invoice::count());
    }

    public function test_the_sale_page_offers_the_right_buttons_and_shows_the_print_history(): void
    {
        $sale = $this->sampleInvoice();
        $accountant = $this->user('accountant');
        $admin = $this->user('admin');

        $this->actingAs($accountant)->get("/sales/{$sale->id}")->assertOk()->assertSee('Invoice copies')->assertSee('Print original')->assertSee('Nothing printed yet');

        $this->actingAs($accountant)->postJson("/sales/{$sale->id}/invoice/printed", ['copy' => 'original'])->assertOk();

        $this->actingAs($accountant)->get("/sales/{$sale->id}")->assertOk()->assertDontSee('Print original')->assertDontSee('Reprint original')->assertSee('printed 1 time');
        $this->actingAs($admin)->get("/sales/{$sale->id}")->assertOk()->assertSee('Reprint original');
    }

    public function test_guests_are_sent_to_login(): void
    {
        $sale = $this->sampleInvoice();

        $this->get("/sales/{$sale->id}/invoice")->assertRedirect('/login');
        $this->postJson("/sales/{$sale->id}/invoice/printed", ['copy' => 'original'])->assertUnauthorized();
        $this->assertSame(0, AuditLog::where('event', 'invoice_printed')->count());
    }
}
