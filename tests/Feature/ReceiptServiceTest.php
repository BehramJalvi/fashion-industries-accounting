<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Customer;
use App\Models\JournalEntryLine;
use App\Models\PaymentAllocation;
use App\Models\Product;
use App\Models\Receipt;
use App\Models\Sale;
use App\Models\Unit;
use App\Services\Inventory\OpeningStockService;
use App\Services\Payments\SettlementService;
use App\Services\Sales\SaleService;
use App\Support\AccountCodes as A;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\FiscalYearSeeder;
use Database\Seeders\NumberSequenceSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReceiptServiceTest extends TestCase
{
    use RefreshDatabase;

    private SettlementService $svc;
    private Customer $customer;
    private Product $thread;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([FiscalYearSeeder::class, ChartOfAccountsSeeder::class, NumberSequenceSeeder::class]);
        $this->svc = app(SettlementService::class);
        $this->customer = Customer::create(['code' => 'C001', 'name' => 'Multan Fabrics PVT Limited', 'is_tax_exempt' => true]);   // exempt: round numbers
        $this->thread = Product::create(['code' => 'P1', 'name' => 'Grey Thread', 'unit_id' => Unit::create(['name' => 'Bundle'])->id, 'product_type' => 'finished_good', 'purchase_price' => 0, 'selling_price' => 100]);
        app(OpeningStockService::class)->record($this->thread, 10000, 60, '2026-10-01');
    }

    private function invoice(int $qty, string $price = '100', string $date = '2026-10-05', ?Customer $customer = null): Sale
    {
        $sales = app(SaleService::class);

        return $sales->post($sales->createDraft(['customer_id' => ($customer ?? $this->customer)->id, 'sale_date' => $date],
            [['product_id' => $this->thread->id, 'qty' => $qty, 'unit_price' => $price]]));
    }

    private function bank(): int { return Account::byCode(A::BANK)->id; }

    private function draft(string|int $amount, array $alloc = [], bool $auto = false, array $over = []): Receipt
    {
        return $this->svc->createDraft('receipt', array_merge([
            'customer_id' => $this->customer->id, 'receipt_date' => '2026-10-10', 'method' => 'bank', 'account_id' => $this->bank(), 'amount' => $amount,
        ], $over), $alloc, $auto);
    }

    private function gl(string $code): string
    {
        $r = JournalEntryLine::join('accounts as a', 'a.id', '=', 'journal_entry_lines.account_id')->where('a.code', $code)
            ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) as bal')->value('bal');

        return number_format((float) $r, 2, '.', '');
    }

    private function assertBooksAgree(): void
    {
        $this->assertSame((string) JournalEntryLine::sum('debit'), (string) JournalEntryLine::sum('credit'));
        $this->assertSame(number_format(Customer::all()->sum(fn ($c) => (float) $c->ledgerBalance()), 2, '.', ''), $this->gl(A::RECEIVABLE));
    }

    public function test_receipt_applied_to_an_invoice_reduces_what_is_owed(): void
    {
        $sale = $this->invoice(100);                                        // 10,000
        $receipt = $this->draft(4000, [$sale->id => 4000]);

        $this->assertSame('draft', $receipt->status);
        $this->assertSame('RCT-0001', $receipt->receipt_no);
        $this->assertSame('10000.00', $sale->fresh()->balanceDue());        // a draft changes nothing
        $this->assertSame('0.00', $this->gl(A::BANK));

        $this->svc->post($receipt);

        $sale = $sale->fresh();
        $this->assertSame('partial', $sale->paymentStatus());
        $this->assertSame('6000.00', $sale->balanceDue());
        $this->assertSame('6000.00', $this->customer->ledgerBalance());
        $this->assertSame('4000.00', $this->gl(A::BANK));
        $this->assertSame(0, $receipt->fresh()->unallocatedCents());
        $this->assertBooksAgree();
    }

    public function test_auto_allocation_pays_the_oldest_invoices_first(): void
    {
        $a = $this->invoice(10, '100', '2026-10-02');                       // 1,000
        $b = $this->invoice(20, '100', '2026-10-03');                       // 2,000

        $this->svc->post($this->draft(1500, auto: true));

        $this->assertSame('paid', $a->fresh()->paymentStatus());
        $this->assertSame('partial', $b->fresh()->paymentStatus());
        $this->assertSame('1500.00', $b->fresh()->balanceDue());
        $this->assertSame('1500.00', $this->customer->ledgerBalance());
        $this->assertBooksAgree();
    }

    public function test_an_overpayment_stays_on_account_and_can_be_applied_to_a_later_invoice(): void
    {
        $first = $this->invoice(100);                                       // 10,000
        $receipt = $this->svc->post($this->draft(10200, auto: true));

        $this->assertSame('paid', $first->fresh()->paymentStatus());
        $this->assertSame(20000, $receipt->unallocatedCents());             // 200.00 on account
        $this->assertSame('-200.00', $this->customer->ledgerBalance());     // customer is in credit

        $second = $this->invoice(5);                                        // 500
        $this->assertSame(20000, $this->svc->applyLater($receipt, [$second->id => 200]));

        $this->assertSame('300.00', $second->fresh()->balanceDue());
        $this->assertSame(0, $receipt->fresh()->unallocatedCents());
        $this->assertSame('300.00', $this->customer->ledgerBalance());      // applying does not move the ledger, only the invoice
        $this->assertBooksAgree();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('already been applied in full');
        $this->svc->applyLater($receipt, [$second->id => 1]);
    }

    public function test_applying_more_than_is_on_account_is_refused(): void
    {
        $this->invoice(100);
        $receipt = $this->svc->post($this->draft(10200, auto: true));
        $second = $this->invoice(5);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('is available');
        $this->svc->applyLater($receipt, [$second->id => 300]);              // only 200 is on account
    }

    public function test_allocation_rules(): void
    {
        $mine = $this->invoice(10);                                         // 1,000
        $other = Customer::create(['code' => 'C002', 'name' => 'Ellahi Fabrics', 'is_tax_exempt' => true]);
        $theirs = $this->invoice(10, '100', '2026-10-05', $other);
        $draftSale = app(SaleService::class)->createDraft(['customer_id' => $this->customer->id, 'sale_date' => '2026-10-05'], [['product_id' => $this->thread->id, 'qty' => 1, 'unit_price' => 10]]);

        $cases = [
            'more than the invoice owes'    => [fn () => $this->draft(5000, [$mine->id => 2000]), 'outstanding'],
            'more than the receipt amount'  => [fn () => $this->draft(1000, [$mine->id => 600, $this->invoice(10)->id => 600]), 'is available'],
            "another customer's invoice"    => [fn () => $this->draft(500, [$theirs->id => 500]), 'not open for this party'],
            'a draft invoice'               => [fn () => $this->draft(500, [$draftSale->id => 500]), 'not open for this party'],
            'negative amount'               => [fn () => $this->draft(500, [$mine->id => -5]), 'cannot be negative'],
            'zero receipt'                  => [fn () => $this->draft(0), 'greater than zero'],
            'not a cash or bank account'    => [fn () => $this->draft(500, [], false, ['account_id' => Account::byCode(A::SALES)->id]), 'cash or bank account'],
        ];

        foreach ($cases as $label => [$attempt, $expected]) {
            try {
                $attempt();
                $this->fail("Expected DomainException for: {$label}");
            } catch (DomainException $e) {
                $this->assertStringContainsString($expected, $e->getMessage(), $label);
            }
        }
        $this->assertSame(0, Receipt::count());
        $this->assertSame(0, PaymentAllocation::count());
    }

    public function test_posting_rechecks_what_the_invoice_still_owes(): void
    {
        $sale = $this->invoice(10);                                         // 1,000
        $stale = $this->draft(800, [$sale->id => 800]);                     // drafted while 1,000 was owed
        $this->svc->post($this->draft(600, [$sale->id => 600]));            // meanwhile 600 is paid -> 400 left

        try {
            $this->svc->post($stale);
            $this->fail('Expected DomainException');
        } catch (DomainException $e) {
            $this->assertStringContainsString('now only has 400.00 outstanding', $e->getMessage());
        }

        $this->assertSame('draft', $stale->fresh()->status);
        $this->assertSame('400.00', $sale->fresh()->balanceDue());
        $this->assertSame('600.00', $this->gl(A::BANK));
    }

    public function test_cancelling_a_receipt_releases_its_allocations(): void
    {
        $sale = $this->invoice(100);
        $receipt = $this->svc->post($this->draft(10000, [$sale->id => 10000]));
        $this->assertSame('paid', $sale->fresh()->paymentStatus());

        try {
            app(SaleService::class)->cancel($sale->fresh(), 'x');           // an invoice with receipts applied cannot be cancelled
            $this->fail('Expected DomainException');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Receipts have been applied', $e->getMessage());
        }

        $receipt = $this->svc->cancel($receipt, 'bounced cheque');

        $this->assertSame('cancelled', $receipt->status);
        $this->assertSame('unpaid', $sale->fresh()->paymentStatus());
        $this->assertSame('10000.00', $this->customer->ledgerBalance());
        $this->assertSame('0.00', $this->gl(A::BANK));
        $this->assertSame(1, PaymentAllocation::where('is_active', false)->count());      // kept for the audit trail, switched off
        $this->assertBooksAgree();

        app(SaleService::class)->cancel($sale->fresh(), 'now allowed');
        $this->assertSame('cancelled', $sale->fresh()->status);
    }

    public function test_posted_receipts_are_locked_and_only_drafts_can_be_deleted(): void
    {
        $sale = $this->invoice(10);
        $draft = $this->draft(500, [$sale->id => 500]);
        $this->svc->deleteDraft($draft);
        $this->assertSame(0, Receipt::count());
        $this->assertSame(0, PaymentAllocation::count());

        $posted = $this->svc->post($this->draft(500, [$sale->id => 500]));
        try {
            $this->svc->deleteDraft($posted);
            $this->fail('Expected DomainException');
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame(1, PaymentAllocation::count());                   // refusing the delete keeps the allocations

        $this->expectException(DomainException::class);
        $posted->update(['amount' => 1]);
    }
}
