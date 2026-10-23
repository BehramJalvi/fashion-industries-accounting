<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Accounting\PostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BooksScenario;
use Tests\TestCase;

class ReportScreensTest extends TestCase
{
    use BooksScenario, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildScenario();
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->roles()->attach(Role::where('name', $role)->first());

        return $u;
    }

    private function viewOnlyUser(): User
    {
        $role = Role::create(['name' => 'report-viewer', 'label' => 'Report viewer']);
        $role->permissions()->attach(Permission::whereIn('name', ['reports.view', 'ledgers.view'])->pluck('id'));
        $u = User::factory()->create();
        $u->roles()->attach($role);

        return $u;
    }

    public function test_every_report_page_opens_for_the_accountant(): void
    {
        $accountant = $this->user('accountant');
        $urls = ['/reports', '/ledgers', '/ledgers?preset=bank', '/reports/trial-balance', '/reports/profit-loss', '/reports/balance-sheet', '/reports/receivables', '/reports/payables',
            '/reports/stock-valuation', '/reports/cash-flow', '/reports/day-book', '/reports/balances/customers', '/reports/balances/suppliers', '/reports/balances/employees', '/reports/balances/owners',
            '/reports/documents/sales', '/reports/documents/purchases', '/reports/documents/sales-returns', '/reports/documents/purchase-returns', '/reports/documents/expenses',
            '/reports/documents/receipts', '/reports/documents/payments'];

        foreach ($urls as $url) {
            $this->actingAs($accountant)->get($url.(str_contains($url, '?') ? '&' : '?').'from=2026-10-01&to=2026-10-31')->assertOk();
        }
    }

    public function test_the_statements_show_the_worked_out_figures(): void
    {
        $accountant = $this->user('accountant');
        $q = '?from=2026-10-01&to=2026-10-31';

        $this->actingAs($accountant)->get('/reports/trial-balance'.$q)->assertSee('In balance')->assertSee('450,000.00')->assertSee('500,000.00');
        $this->actingAs($accountant)->get('/reports/profit-loss'.$q)->assertSee('165,000.00')->assertSee('112,000.00')->assertSee('53,000.00')->assertSee('55,000.00')->assertSee('-2,000.00')->assertSee('Net loss');
        $this->actingAs($accountant)->get('/reports/balance-sheet?to=2026-10-31')->assertSee('607,700.00')->assertSee('498,000.00')->assertSee('Balanced');
        $this->actingAs($accountant)->get('/reports/cash-flow'.$q)->assertSee('475,000.00')->assertSee('Owner capital and drawings');
        $this->actingAs($accountant)->get('/reports/stock-valuation')->assertSee('38,000.00')->assertSee('Cotton Yarn');
        $this->actingAs($accountant)->get('/reports/receivables?as_of=2026-10-20')->assertSee('Multan Fabrics PVT Limited')->assertSee('94,700.00')->assertSee('Not yet due');
        $this->actingAs($accountant)->get('/reports/payables?as_of=2026-10-20')->assertSee('Al Zamin')->assertSee('40,000.00')->assertSee('Mohsin');
        $this->actingAs($accountant)->get('/reports/balances/customers?to=2026-10-31')->assertSee('94,700.00')->assertSee('Owes us');
        $this->actingAs($accountant)->get('/reports/day-book?from=2026-10-05&to=2026-10-05')->assertSee('sale')->assertSee('Sales invoice');
        $this->actingAs($accountant)->get('/reports/documents/sales'.$q)->assertSee('Multan Fabrics PVT Limited')->assertSee('212,400.00')->assertSee('553');
    }

    public function test_the_ledger_screen_with_presets_and_filters(): void
    {
        $accountant = $this->user('accountant');

        $this->actingAs($accountant)->get('/ledgers?preset=bank&from=2026-10-01&to=2026-10-31')->assertOk()->assertSee('Ledger: Bank')->assertSee('450,000.00')->assertSee('Opening balance');
        $this->actingAs($accountant)->get('/ledgers?preset=bank&from=2026-10-01&to=2026-10-31&voucher_type=receipt')->assertOk()->assertSee('100,000.00')->assertSee('no running balance');
        $this->actingAs($accountant)->get('/ledgers?account_id='.Account::byCode('1100')->id.'&party=customer:'.$this->customer->id.'&from=2026-10-01&to=2026-10-31')
            ->assertOk()->assertSee('Customer: Multan Fabrics PVT Limited')->assertSee('94,700.00');
        $this->actingAs($accountant)->get('/ledgers')->assertOk()->assertSee('Choose an account');
    }

    public function test_all_account_ledgers_print_with_independent_balances(): void
    {
        $accountant = $this->user('accountant');

        $response = $this->actingAs($accountant)->get('/ledgers?all=1&from=2026-10-01&to=2026-10-31');
        $response
            ->assertSee('All Account Ledgers')
            ->assertSee('1000 · Cash')
            ->assertSee('1010 · Bank')
            ->assertSee('25,000.00')
            ->assertSee('450,000.00')
            ->assertSee('No transactions in this period.')
            ->assertDontSee('Export CSV');

        $ledgers = collect($response->viewData('allLedgers'))->keyBy(fn ($ledger) => $ledger['account']->code);
        $this->assertSame(2500000, $ledgers['1000']['statement']['closing']);
        $this->assertSame(45000000, $ledgers['1010']['statement']['closing']);
    }

    public function test_dates_are_validated(): void
    {
        $accountant = $this->user('accountant');

        $this->actingAs($accountant)->get('/reports/profit-loss?from=2026-10-31&to=2026-10-01')->assertSessionHasErrors('to');
        $this->actingAs($accountant)->get('/reports/profit-loss?from=not-a-date')->assertSessionHasErrors('from');
        $this->actingAs($accountant)->get('/ledgers?all=not-a-boolean')->assertSessionHasErrors('all');
        $this->actingAs($accountant)->get('/ledgers?preset=nonsense')->assertSessionHasErrors('preset');
        $this->actingAs($accountant)->get('/reports/documents/unknown-type')->assertNotFound();
        $this->actingAs($accountant)->get('/reports/balances/strangers')->assertNotFound();
    }

    public function test_csv_export_works_and_needs_the_export_permission(): void
    {
        $accountant = $this->user('accountant');

        $res = $this->actingAs($accountant)->get('/reports/trial-balance?from=2026-10-01&to=2026-10-31&export=csv')->assertOk();
        $this->assertStringContainsString('text/csv', $res->headers->get('Content-Type'));
        $csv = $res->streamedContent();
        $this->assertStringContainsString('Code,Account,Type,"Opening Dr"', $csv);                // a header cell with a space is quoted by fputcsv
        $this->assertStringContainsString('450000.00', $csv);       // plain numbers, no thousands separators, ready for a spreadsheet
        $this->assertStringContainsString('TOTAL', $csv);

        $pl = $this->actingAs($accountant)->get('/reports/profit-loss?from=2026-10-01&to=2026-10-31&export=csv')->streamedContent();
        $this->assertStringContainsString('NET PROFIT / (LOSS)', $pl);
        $this->assertStringContainsString('-2000.00', $pl);

        foreach (['/reports/balance-sheet?to=2026-10-31', '/reports/receivables', '/reports/payables', '/reports/stock-valuation', '/reports/cash-flow', '/reports/day-book', '/reports/balances/customers', '/reports/documents/sales'] as $url) {
            $this->actingAs($accountant)->get($url.(str_contains($url, '?') ? '&' : '?').'export=csv')->assertOk();
        }

        $viewer = $this->viewOnlyUser();                              // can read reports, but not download them
        $this->actingAs($viewer)->get('/reports/trial-balance')->assertOk();
        $this->actingAs($viewer)->get('/reports/trial-balance?export=csv')->assertForbidden();
        $this->actingAs($viewer)->get('/ledgers?preset=bank&export=csv')->assertForbidden();
    }

    public function test_csv_cells_that_look_like_formulas_are_neutralised(): void
    {
        app(PostingService::class)->post(['date' => '2026-10-20', 'voucher_type' => 'journal', 'description' => '=HYPERLINK("http://evil")', 'lines' => [
            ['account' => '1000', 'debit' => 5], ['account' => '4000', 'credit' => 5]]]);

        $csv = $this->actingAs($this->user('accountant'))->get('/ledgers?preset=cash&from=2026-10-01&to=2026-10-31&export=csv')->streamedContent();

        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringNotContainsString(',=HYPERLINK', $csv);
    }

    public function test_access_control(): void
    {
        foreach (['/reports', '/ledgers', '/reports/trial-balance', '/reports/profit-loss', '/reports/balance-sheet'] as $url) {
            $this->get($url)->assertRedirect('/login');
        }

        $nobody = User::factory()->create();                         // no role at all
        foreach (['/reports', '/ledgers', '/reports/trial-balance', '/reports/profit-loss', '/reports/balance-sheet', '/reports/documents/sales', '/reports/day-book'] as $url) {
            $this->actingAs($nobody)->get($url)->assertForbidden();
        }
    }
}
