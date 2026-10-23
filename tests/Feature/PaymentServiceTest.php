<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Employee;
use App\Models\JournalEntryLine;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\Unit;
use App\Services\Payments\ExpenseService;
use App\Services\Payments\SettlementService;
use App\Services\Purchasing\PurchaseService;
use App\Support\AccountCodes as A;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\FiscalYearSeeder;
use Database\Seeders\NumberSequenceSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentServiceTest extends TestCase
{
    use RefreshDatabase;

    private SettlementService $svc;
    private Supplier $alZamin;
    private Employee $mohsin;
    private Product $yarn;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([FiscalYearSeeder::class, ChartOfAccountsSeeder::class, NumberSequenceSeeder::class]);
        $this->svc = app(SettlementService::class);
        $this->alZamin = Supplier::create(['code' => 'S001', 'name' => 'Al Zamin']);
        $this->mohsin = Employee::create(['code' => 'E001', 'name' => 'Mohsin']);
        $this->yarn = Product::create(['code' => 'Y1', 'name' => 'Cotton Yarn', 'unit_id' => Unit::create(['name' => 'Bundle'])->id, 'product_type' => 'raw_material', 'purchase_price' => 0, 'selling_price' => 0]);
    }

    private function bank(): int { return Account::byCode(A::BANK)->id; }

    private function buy(int $qty, string $cost, array $header = []): Purchase
    {
        $p = app(PurchaseService::class);

        return $p->post($p->createDraft(array_merge(['purchase_date' => '2026-10-05', 'supplier_id' => $this->alZamin->id], $header), [['product_id' => $this->yarn->id, 'qty' => $qty, 'unit_cost' => $cost]]));
    }

    private function pay(string $type, int $id, string|int $amount, array $alloc = [], bool $auto = false, string $date = '2026-10-10'): Payment
    {
        return $this->svc->createDraft('payment', ['payee_type' => $type, 'payee_id' => $id, 'payment_date' => $date, 'method' => 'bank', 'account_id' => $this->bank(), 'amount' => $amount], $alloc, $auto);
    }

    private function gl(string $code): string
    {
        $r = JournalEntryLine::join('accounts as a', 'a.id', '=', 'journal_entry_lines.account_id')->where('a.code', $code)
            ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) as bal')->value('bal');

        return number_format((float) $r, 2, '.', '');
    }

    private function assertJournalBalances(): void
    {
        $this->assertSame((string) JournalEntryLine::sum('debit'), (string) JournalEntryLine::sum('credit'));
    }

    public function test_al_zamin_part_payments_settle_the_bill_step_by_step(): void
    {
        $bill = $this->buy(100, '1953');                                    // owe 195,300

        $this->svc->post($this->pay('supplier', $this->alZamin->id, 50000, [$bill->id => 50000]));
        $this->assertSame('partial', $bill->fresh()->paymentStatus());
        $this->assertSame('145300.00', $bill->fresh()->balanceDue());

        $this->svc->post($this->pay('supplier', $this->alZamin->id, 100000, [$bill->id => 100000]));
        $this->assertSame('45300.00', $bill->fresh()->balanceDue());
        $this->assertSame('45300.00', $this->alZamin->ledgerBalance());

        $last = $this->svc->post($this->pay('supplier', $this->alZamin->id, 45300, auto: true));
        $this->assertSame('paid', $bill->fresh()->paymentStatus());
        $this->assertSame('0.00', $this->alZamin->ledgerBalance());
        $this->assertSame('-195300.00', $this->gl(A::BANK));
        $this->assertSame(0, $last->fresh()->unallocatedCents());
        $this->assertSame('PAY-0003', $last->payment_no);
        $this->assertJournalBalances();
    }

    public function test_reimbursing_an_employee_for_a_purchase_he_paid_for(): void
    {
        $bill = $this->buy(1, '600', ['supplier_id' => null, 'funded_by_employee_id' => $this->mohsin->id, 'supplier_ref' => 'BILL-002']);
        $this->assertSame('600.00', $this->mohsin->ledgerBalance());

        $this->svc->post($this->pay('employee', $this->mohsin->id, 600, [$bill->id => 600]));

        $this->assertSame('paid', $bill->fresh()->paymentStatus());
        $this->assertSame('0.00', $this->mohsin->ledgerBalance());
        $this->assertJournalBalances();
    }

    public function test_salary_payable_then_paid_with_a_small_overpayment(): void
    {
        // Salary of 40,000 booked as owed to Mohsin, then 5,000 + 35,090 paid: he now owes the business 90.
        $expenses = app(ExpenseService::class);
        $expenses->post($expenses->createDraft(['expense_date' => '2026-10-01', 'expense_account_id' => Account::byCode('5200')->id, 'amount' => 40000,
            'description' => 'Salary - Mohsin', 'payee_type' => 'employee', 'payee_id' => $this->mohsin->id]));
        $this->assertSame('40000.00', $this->mohsin->ledgerBalance());

        $first = $this->svc->post($this->pay('employee', $this->mohsin->id, 5000));
        $this->svc->post($this->pay('employee', $this->mohsin->id, 35090));

        $this->assertSame('-90.00', $this->mohsin->ledgerBalance());
        $this->assertSame(500000, $first->unallocatedCents());              // salary is not a bill: the money simply reduces his balance
        $this->assertSame('-40090.00', $this->gl(A::BANK));
        $this->assertJournalBalances();
    }

    public function test_an_advance_to_a_supplier_can_be_applied_to_a_later_bill(): void
    {
        $advance = $this->svc->post($this->pay('supplier', $this->alZamin->id, 10000));
        $this->assertSame('-10000.00', $this->alZamin->ledgerBalance());    // we have paid in advance

        $bill = $this->buy(6, '1000');                                      // 6,000
        $this->assertSame('-4000.00', $this->alZamin->ledgerBalance());
        $this->assertSame('unpaid', $bill->fresh()->paymentStatus());       // until the advance is applied to it

        $this->svc->applyLater($advance, [$bill->id => 6000]);

        $this->assertSame('paid', $bill->fresh()->paymentStatus());
        $this->assertSame(400000, $advance->fresh()->unallocatedCents());
    }

    public function test_a_purchase_an_employee_paid_for_is_owed_to_the_employee_not_the_supplier(): void
    {
        $bill = $this->buy(1, '600', ['funded_by_employee_id' => $this->mohsin->id]);   // supplier recorded, but Mohsin paid

        try {
            $this->pay('supplier', $this->alZamin->id, 600, [$bill->id => 600]);
            $this->fail('Expected DomainException');
        } catch (DomainException $e) {
            $this->assertStringContainsString('not open for this party', $e->getMessage());
        }

        $this->svc->post($this->pay('employee', $this->mohsin->id, 600, [$bill->id => 600]));
        $this->assertSame('paid', $bill->fresh()->paymentStatus());
    }

    public function test_payment_rules(): void
    {
        $bill = $this->buy(1, '1000');
        $cases = [
            'more than the bill owes' => [fn () => $this->pay('supplier', $this->alZamin->id, 5000, [$bill->id => 2000]), 'outstanding'],
            'zero amount'             => [fn () => $this->pay('supplier', $this->alZamin->id, 0), 'greater than zero'],
            'unknown payee type'      => [fn () => $this->pay('customer', 1, 100), 'supplier or an employee'],
        ];
        foreach ($cases as $label => [$attempt, $expected]) {
            try {
                $attempt();
                $this->fail("Expected DomainException for: {$label}");
            } catch (DomainException $e) {
                $this->assertStringContainsString($expected, $e->getMessage(), $label);
            }
        }
        $this->assertSame(0, Payment::count());
    }

    public function test_cancelling_a_payment_makes_the_bill_unpaid_again_and_frees_the_purchase_for_cancellation(): void
    {
        $bill = $this->buy(10, '100');
        $payment = $this->svc->post($this->pay('supplier', $this->alZamin->id, 1000, [$bill->id => 1000]));
        $this->assertSame('paid', $bill->fresh()->paymentStatus());

        try {
            app(PurchaseService::class)->cancel($bill->fresh(), 'x');
            $this->fail('Expected DomainException');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Payments have been applied', $e->getMessage());
        }

        $payment = $this->svc->cancel($payment, 'wrong supplier');

        $this->assertSame('cancelled', $payment->status);
        $this->assertSame('unpaid', $bill->fresh()->paymentStatus());
        $this->assertSame('1000.00', $this->alZamin->ledgerBalance());
        $this->assertSame('0.00', $this->gl(A::BANK));
        $this->assertJournalBalances();

        app(PurchaseService::class)->cancel($bill->fresh(), 'now allowed');
        $this->assertSame('cancelled', $bill->fresh()->status);
    }

    public function test_posting_rechecks_what_the_bill_still_owes(): void
    {
        $bill = $this->buy(10, '100');                                      // 1,000
        $stale = $this->pay('supplier', $this->alZamin->id, 800, [$bill->id => 800]);
        $this->svc->post($this->pay('supplier', $this->alZamin->id, 600, [$bill->id => 600]));

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('now only has 400.00 outstanding');
        $this->svc->post($stale);
    }
}
