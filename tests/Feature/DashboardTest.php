<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Inventory\InventoryService;
use App\Services\Sales\SaleService;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BooksScenario;
use Tests\TestCase;

/** "Today" is fixed at 31-Oct-2026 so the month of trading in BooksScenario is the current month. */
class DashboardTest extends TestCase
{
    use BooksScenario, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-31 10:00:00'));
        $this->buildScenario();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->roles()->attach(Role::where('name', $role)->first());

        return $u;
    }

    public function test_the_headline_figures_match_the_hand_calculation(): void
    {
        $page = $this->actingAs($this->user('accountant'))->get('/dashboard')->assertOk();

        $page->assertSee('This month')->assertSee('01 Oct – 31 Oct 2026')
            ->assertSee('Net sales')->assertSee('165,000.00')->assertSee('1 invoice')->assertSee('gross profit 53,000.00')->assertSee('(32.1%)')
            ->assertSee('Purchases')->assertSee('150,000.00')->assertSee('2 bills')
            ->assertSee('Net loss')->assertSee('-2,000.00')->assertSee('55,000.00')
            ->assertSee('Receivables')->assertSee('94,700.00')->assertSee('nothing overdue')
            ->assertSee('Payables')->assertSee('80,000.00')->assertSee('suppliers 40,000.00')->assertSee('employees 40,000.00')
            ->assertSee('Cash balance')->assertSee('25,000.00')->assertSee('Bank balance')->assertSee('450,000.00')
            ->assertSee('Inventory value')->assertSee('38,000.00')->assertSee('29,700.00')
            ->assertDontSee('Inventory does not reconcile');
    }

    public function test_recent_transactions_and_the_trend(): void
    {
        $page = $this->actingAs($this->user('accountant'))->get('/dashboard')->assertOk();

        $page->assertSee('Recent sales')->assertSee('Multan Fabrics PVT Limited')->assertSee('212,400.00')
            ->assertSee('Recent purchases')->assertSee('PUR-0001')->assertSee('Walk-in')
            ->assertSee('Recent money movements')->assertSee('Factory rent')
            ->assertSee('Last six months')->assertSee('Oct 2026')->assertSee('May 2026');
        $page->assertSee('Cash and bank accounts')->assertSee('Bank')->assertSee('Cash in Hand');
    }

    public function test_the_period_switch(): void
    {
        $accountant = $this->user('accountant');

        foreach (['fy', 'last30'] as $period) {                                  // all the activity falls inside these windows too
            $this->actingAs($accountant)->get("/dashboard?period={$period}")->assertOk()->assertSee('165,000.00')->assertSee('-2,000.00');
        }
        $this->actingAs($accountant)->get('/dashboard?period=fy')->assertSee('This fiscal year');

        // only the sale and its cost exist by the 9th: 180,000 - 120,000 = a profit of 60,000
        $this->actingAs($accountant)->get('/dashboard?period=custom&from=2026-10-01&to=2026-10-09')->assertOk()
            ->assertSee('Custom period')->assertSee('180,000.00')->assertSee('Net profit')->assertSee('60,000.00');

        $this->actingAs($accountant)->get('/dashboard?period=bogus')->assertSessionHasErrors('period');
        $this->actingAs($accountant)->get('/dashboard?period=custom&from=2026-10-31&to=2026-10-01')->assertSessionHasErrors('to');
    }

    public function test_low_stock_lists_products_below_their_minimum(): void
    {
        $accountant = $this->user('accountant');
        $this->actingAs($accountant)->get('/dashboard')->assertSee('Every product is at or above its minimum stock.');

        $this->yarn->update(['min_stock' => 50]);                               // 38 in stock

        $this->actingAs($accountant)->get('/dashboard')->assertOk()->assertSee('Cotton Yarn')->assertSee('38 Cone')->assertDontSee('Every product is at or above');
    }

    public function test_drafts_waiting_to_be_posted_are_listed_with_a_link(): void
    {
        $accountant = $this->user('accountant');
        $this->actingAs($accountant)->get('/dashboard')->assertSee('No drafts waiting');

        $sales = app(SaleService::class);
        $sales->createDraft(['customer_id' => $this->customer->id, 'sale_date' => '2026-10-20'], [['product_id' => $this->yarn->id, 'qty' => 1, 'unit_price' => 100]]);

        $this->actingAs($accountant)->get('/dashboard')->assertOk()->assertSee('Sales invoices')->assertSee('saved as draft')->assertSee('status=draft', false);
    }

    public function test_a_stock_ledger_that_disagrees_with_the_inventory_account_raises_a_warning(): void
    {
        // stock added straight into the stock ledger with no journal behind it: exactly the fault the control check exists to catch
        app(InventoryService::class)->receive($this->yarn, 1, 'adjustment_in', '2026-10-30', unitCost: 100);

        $this->actingAs($this->user('accountant'))->get('/dashboard')->assertOk()->assertSee('Inventory does not reconcile')->assertSee('100.00');
    }

    public function test_a_role_without_the_reports_permission_sees_no_money_totals(): void
    {
        $role = Role::create(['name' => 'basic', 'label' => 'Basic']);
        $role->permissions()->attach(Permission::whereIn('name', ['dashboard.view', 'inventory.view'])->pluck('id'));
        $basic = User::factory()->create();
        $basic->roles()->attach($role);
        $this->yarn->update(['min_stock' => 50]);

        $this->actingAs($basic)->get('/dashboard')->assertOk()->assertSee('Welcome')->assertSee('Low stock')->assertSee('Cotton Yarn')
            ->assertDontSee('Net sales')->assertDontSee('165,000.00')->assertDontSee('Receivables')->assertDontSee('450,000.00')
            ->assertDontSee('Recent sales')->assertDontSee('Recent money movements');
    }

    public function test_access_control(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get('/dashboard')->assertForbidden();         // no role
    }
}
