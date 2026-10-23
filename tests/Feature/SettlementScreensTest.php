<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\FundTransfer;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Receipt;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\Inventory\OpeningStockService;
use App\Services\Purchasing\PurchaseService;
use App\Services\Sales\SaleService;
use App\Support\AccountCodes as A;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\FiscalYearSeeder;
use Database\Seeders\NumberSequenceSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettlementScreensTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;
    private Supplier $supplier;
    private Employee $mohsin;
    private Product $product;
    private Sale $invoice;
    private Purchase $bill;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, FiscalYearSeeder::class, ChartOfAccountsSeeder::class, NumberSequenceSeeder::class]);
        $this->customer = Customer::create(['code' => 'C001', 'name' => 'Multan Fabrics PVT Limited', 'is_tax_exempt' => true, 'is_active' => true]);
        $this->supplier = Supplier::create(['code' => 'S001', 'name' => 'Al Zamin', 'is_active' => true]);
        $this->mohsin = Employee::create(['code' => 'E001', 'name' => 'Mohsin', 'is_active' => true]);
        $this->product = Product::create(['code' => 'P1', 'name' => 'Grey Thread', 'unit_id' => Unit::create(['name' => 'Bundle'])->id, 'product_type' => 'finished_good', 'purchase_price' => 0, 'selling_price' => 100]);
        app(OpeningStockService::class)->record($this->product, 1000, 60, '2026-10-01');

        $sales = app(SaleService::class);
        $this->invoice = $sales->post($sales->createDraft(['customer_id' => $this->customer->id, 'sale_date' => '2026-10-05'], [['product_id' => $this->product->id, 'qty' => 100, 'unit_price' => 100]]));   // 10,000
        $purchases = app(PurchaseService::class);
        $this->bill = $purchases->post($purchases->createDraft(['purchase_date' => '2026-10-05', 'supplier_id' => $this->supplier->id], [['product_id' => $this->product->id, 'qty' => 10, 'unit_cost' => 100]]));   // 1,000
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->roles()->attach(Role::where('name', $role)->first());

        return $u;
    }

    private function bank(): int { return Account::byCode(A::BANK)->id; }

    public function test_accountant_records_a_receipt_applied_to_an_invoice(): void
    {
        $accountant = $this->user('accountant');

        $this->actingAs($accountant)->get('/receipts/create')->assertOk()->assertSee('Multan Fabrics');
        $this->actingAs($accountant)->get('/receipts/create?customer_id=' . $this->customer->id)->assertOk()->assertSee('Open invoices')->assertSee("Invoice {$this->invoice->invoice_no}");

        $this->actingAs($accountant)->post('/receipts', [
            'customer_id' => $this->customer->id, 'receipt_date' => '2026-10-10', 'method' => 'bank', 'account_id' => $this->bank(), 'amount' => '4000',
            'alloc' => [$this->invoice->id => '4000'], 'action' => 'post',
        ])->assertSessionHasNoErrors();

        $receipt = Receipt::firstOrFail();
        $this->assertSame('posted', $receipt->status);
        $this->assertSame('partial', $this->invoice->fresh()->paymentStatus());
        $this->actingAs($accountant)->get("/receipts/{$receipt->id}")->assertOk()->assertSee('RCT-0001')->assertSee('On account');
        $this->actingAs($accountant)->get('/receipts')->assertOk()->assertSee('4,000.00');
    }

    public function test_auto_allocate_and_apply_later_through_the_screens(): void
    {
        $accountant = $this->user('accountant');
        $this->actingAs($accountant)->post('/receipts', [
            'customer_id' => $this->customer->id, 'receipt_date' => '2026-10-10', 'method' => 'cash', 'account_id' => Account::byCode(A::CASH)->id, 'amount' => '10500',
            'auto_allocate' => '1', 'action' => 'post',
        ])->assertSessionHasNoErrors();

        $receipt = Receipt::firstOrFail();
        $this->assertSame('paid', $this->invoice->fresh()->paymentStatus());
        $this->assertSame(50000, $receipt->unallocatedCents());                    // 500.00 left on account

        $sales = app(SaleService::class);
        $second = $sales->post($sales->createDraft(['customer_id' => $this->customer->id, 'sale_date' => '2026-10-11'], [['product_id' => $this->product->id, 'qty' => 10, 'unit_price' => 100]]));   // 1,000

        $this->actingAs($accountant)->post("/receipts/{$receipt->id}/allocate", ['alloc' => [$second->id => '500']])->assertSessionHas('success');
        $this->assertSame('500.00', $second->fresh()->balanceDue());
        $this->assertSame(0, $receipt->fresh()->unallocatedCents());
    }

    public function test_over_allocation_is_reported_and_saves_nothing(): void
    {
        $this->actingAs($this->user('accountant'))->post('/receipts', [
            'customer_id' => $this->customer->id, 'receipt_date' => '2026-10-10', 'method' => 'bank', 'account_id' => $this->bank(), 'amount' => '50000',
            'alloc' => [$this->invoice->id => '20000'], 'action' => 'post',
        ])->assertSessionHasErrors('receipt');

        $this->assertSame(0, Receipt::count());
        $this->assertSame(0, PaymentAllocation::count());
    }

    public function test_receipt_cancellation_is_admin_only_and_needs_a_reason(): void
    {
        $this->actingAs($this->user('accountant'))->post('/receipts', [
            'customer_id' => $this->customer->id, 'receipt_date' => '2026-10-10', 'method' => 'bank', 'account_id' => $this->bank(), 'amount' => '10000',
            'alloc' => [$this->invoice->id => '10000'], 'action' => 'post',
        ]);
        $receipt = Receipt::firstOrFail();

        $this->actingAs($this->user('accountant'))->post("/receipts/{$receipt->id}/cancel", ['reason' => 'x'])->assertForbidden();

        $admin = $this->user('admin');
        $this->actingAs($admin)->post("/receipts/{$receipt->id}/cancel", ['reason' => ''])->assertSessionHasErrors('reason');
        $this->actingAs($admin)->post("/receipts/{$receipt->id}/cancel", ['reason' => 'bounced'])->assertSessionHas('success');

        $this->assertSame('unpaid', $this->invoice->fresh()->paymentStatus());
    }

    public function test_draft_receipt_can_be_deleted_but_a_posted_one_cannot(): void
    {
        $accountant = $this->user('accountant');
        $base = ['customer_id' => $this->customer->id, 'receipt_date' => '2026-10-10', 'method' => 'bank', 'account_id' => $this->bank(), 'amount' => '1000', 'alloc' => [$this->invoice->id => '1000']];

        $this->actingAs($accountant)->post('/receipts', $base + ['action' => 'draft']);
        $draft = Receipt::firstOrFail();
        $this->assertSame('draft', $draft->status);
        $this->actingAs($accountant)->delete("/receipts/{$draft->id}")->assertRedirect('/receipts');
        $this->assertSame(0, PaymentAllocation::count());

        $this->actingAs($accountant)->post('/receipts', $base + ['action' => 'post']);
        $posted = Receipt::firstOrFail();
        $this->actingAs($accountant)->delete("/receipts/{$posted->id}")->assertSessionHas('error');
        $this->assertSame(1, PaymentAllocation::count());
    }

    public function test_supplier_and_employee_payments_through_the_screens(): void
    {
        $accountant = $this->user('accountant');
        $this->actingAs($accountant)->get('/payments/create')->assertOk()->assertSee('Al Zamin')->assertSee('Mohsin');
        $this->actingAs($accountant)->get('/payments/create?payee=supplier:' . $this->supplier->id)->assertOk()->assertSee('Open bills')->assertSee($this->bill->purchase_no);

        $this->actingAs($accountant)->post('/payments', [
            'payee_type' => 'supplier', 'payee_id' => $this->supplier->id, 'payment_date' => '2026-10-10', 'method' => 'bank', 'account_id' => $this->bank(),
            'amount' => '400', 'alloc' => [$this->bill->id => '400'], 'action' => 'post',
        ])->assertSessionHasNoErrors();
        $this->assertSame('600.00', $this->bill->fresh()->balanceDue());
        $this->assertSame('600.00', $this->supplier->ledgerBalance());

        $this->actingAs($accountant)->post('/payments', [
            'payee_type' => 'employee', 'payee_id' => $this->mohsin->id, 'payment_date' => '2026-10-10', 'method' => 'cash', 'account_id' => Account::byCode(A::CASH)->id,
            'amount' => '90', 'action' => 'post',
        ])->assertSessionHasNoErrors();
        $this->assertSame('-90.00', $this->mohsin->ledgerBalance());

        $payment = Payment::where('payee_type', 'supplier')->firstOrFail();
        $this->actingAs($accountant)->get("/payments/{$payment->id}")->assertOk()->assertSee('PAY-0001');
        $this->actingAs($accountant)->get('/payments')->assertOk()->assertSee('Al Zamin');
        $this->actingAs($accountant)->post("/payments/{$payment->id}/cancel", ['reason' => 'x'])->assertForbidden();
    }

    public function test_expense_screens_paid_now_on_credit_and_validation(): void
    {
        $accountant = $this->user('accountant');
        $rent = Account::byCode('5300')->id;
        $base = ['expense_date' => '2026-10-05', 'expense_account_id' => $rent, 'amount' => '15000', 'description' => 'Factory rent', 'action' => 'post'];

        $this->actingAs($accountant)->post('/expenses', $base + ['paid_from_account_id' => Account::byCode(A::CASH)->id])->assertSessionHasNoErrors();
        $this->assertSame('posted', Expense::firstOrFail()->status);

        $this->actingAs($accountant)->post('/expenses', array_merge($base, ['expense_account_id' => Account::byCode(A::PROCESSING_CHARGES)->id, 'description' => 'Dyeing', 'owed_to' => 'supplier:' . $this->supplier->id]))->assertSessionHasNoErrors();
        $this->assertSame('16000.00', $this->supplier->ledgerBalance());   // 1,000 bill + 15,000 dyeing

        $this->actingAs($accountant)->post('/expenses', $base)->assertSessionHasErrors('expense');                               // neither paid-from nor owed-to
        $this->actingAs($accountant)->post('/expenses', $base + ['paid_from_account_id' => Account::byCode(A::CASH)->id, 'owed_to' => 'supplier:1'])->assertSessionHasErrors('expense');
        $this->actingAs($accountant)->post('/expenses', array_merge($base, ['expense_account_id' => Account::byCode(A::BANK)->id, 'paid_from_account_id' => Account::byCode(A::CASH)->id]))->assertSessionHasErrors('expense_account_id');
        $this->assertSame(2, Expense::count());

        $expense = Expense::firstOrFail();
        $this->actingAs($accountant)->get("/expenses/{$expense->id}")->assertOk()->assertSee('EXP-0001');
        $this->actingAs($accountant)->post("/expenses/{$expense->id}/cancel", ['reason' => 'x'])->assertForbidden();
        $this->actingAs($this->user('admin'))->post("/expenses/{$expense->id}/cancel", ['reason' => 'duplicate'])->assertSessionHas('success');
    }

    public function test_fund_transfer_screens(): void
    {
        $accountant = $this->user('accountant');
        $payload = ['transfer_date' => '2026-10-05', 'from_account_id' => $this->bank(), 'to_account_id' => Account::byCode(A::CASH)->id, 'amount' => '40090', 'reference' => 'CHQ-118', 'action' => 'post'];

        $this->actingAs($accountant)->post('/fund-transfers', array_merge($payload, ['to_account_id' => $this->bank()]))->assertSessionHasErrors('to_account_id');   // same account
        $this->assertSame(0, FundTransfer::count());

        $this->actingAs($accountant)->post('/fund-transfers', $payload)->assertSessionHasNoErrors();
        $t = FundTransfer::firstOrFail();
        $this->assertSame('posted', $t->status);
        $this->actingAs($accountant)->get("/fund-transfers/{$t->id}")->assertOk()->assertSee('TRF-0001')->assertSee('40,090.00');
        $this->actingAs($accountant)->get('/fund-transfers')->assertOk()->assertSee('CHQ-118');
    }

    public function test_guests_and_unauthorised_users_are_blocked(): void
    {
        foreach (['/receipts', '/payments', '/expenses', '/fund-transfers'] as $url) {
            $this->get($url)->assertRedirect('/login');
        }

        $viewer = User::factory()->create();      // no role
        foreach (['/receipts', '/payments', '/expenses', '/fund-transfers'] as $url) {
            $this->actingAs($viewer)->get($url)->assertForbidden();
        }
        $this->actingAs($viewer)->post('/expenses', ['expense_date' => '2026-10-05', 'expense_account_id' => 1, 'amount' => 1, 'description' => 'x'])->assertForbidden();
    }
}
