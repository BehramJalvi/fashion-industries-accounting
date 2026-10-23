<?php

namespace Tests\Feature;

use App\Exceptions\Inventory\InsufficientStockException;
use App\Models\Account;
use App\Models\Customer;
use App\Models\InventoryTransaction;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\PaymentAllocation;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\Unit;
use App\Services\Inventory\OpeningStockService;
use App\Services\Purchasing\PurchaseService;
use App\Services\Sales\SaleService;
use App\Support\AccountCodes as A;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\FiscalYearSeeder;
use Database\Seeders\NumberSequenceSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SaleServiceTest extends TestCase
{
    use RefreshDatabase;

    private SaleService $svc;
    private Customer $customer;
    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([FiscalYearSeeder::class, ChartOfAccountsSeeder::class, NumberSequenceSeeder::class]);
        $this->svc = app(SaleService::class);
        $this->unit = Unit::create(['name' => 'Bundle']);
        $this->customer = Customer::create([
            'code' => 'C001', 'name' => 'Multan Fabrics PVT Limited', 'address' => '17-A Export Zone Ansar Colony Multan',
            'ntn' => '1290353-1', 'strn' => '04-90-9999-247-82',
        ]);
    }

    /** A product with opening stock (which also posts Dr Inventory / Cr Opening Balance Equity). */
    private function product(string $code, string|int $qty, string|int $cost, ?string $name = null): Product
    {
        $p = Product::create(['code' => $code, 'name' => $name ?? "Thread {$code}", 'unit_id' => $this->unit->id, 'product_type' => 'finished_good', 'purchase_price' => 0, 'selling_price' => 100]);
        app(OpeningStockService::class)->record($p, $qty, $cost, '2026-10-01');

        return $p;
    }

    private function header(array $over = []): array
    {
        return array_merge(['customer_id' => $this->customer->id, 'sale_date' => '2026-10-05'], $over);
    }

    private function line(Product $p, string|int|float $qty, string|int|float $price, array $over = []): array
    {
        return array_merge(['product_id' => $p->id, 'qty' => $qty, 'unit_price' => $price], $over);
    }

    private function sell(array $header, array $items): Sale
    {
        return $this->svc->post($this->svc->createDraft($header, $items));
    }

    private function gl(string $code): string
    {
        $r = JournalEntryLine::join('accounts as a', 'a.id', '=', 'journal_entry_lines.account_id')->where('a.code', $code)
            ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) as bal')->value('bal');

        return number_format((float) $r, 2, '.', '');
    }

    /** Every sale scenario must keep: journal balanced, stock-ledger value == Inventory account, receivable account == sum of customers. */
    private function assertBooksAgree(): void
    {
        $this->assertSame((string) JournalEntryLine::sum('debit'), (string) JournalEntryLine::sum('credit'));

        $ledger = InventoryTransaction::whereIn('id', InventoryTransaction::query()->selectRaw('MAX(id)')->groupBy('product_id'))->get()->sum(fn ($r) => (float) $r->balance_value);
        $this->assertSame(number_format($ledger, 2, '.', ''), $this->gl(A::INVENTORY));

        $customers = Customer::all()->sum(fn ($c) => (float) $c->ledgerBalance());
        $this->assertSame(number_format($customers, 2, '.', ''), $this->gl(A::RECEIVABLE));
    }

    public function test_sample_invoice_257_posts_correctly(): void
    {
        // The five lines of the sample "Sales Tax Invoice": 591 units at 100, 18% tax -> 59,100 + 10,638 = 69,738
        $names = ['Harbarver Grey Thread' => 237, 'Limpet Shell Thread' => 228, 'Green Thread' => 42, 'Grey Thread' => 42, 'Yellow Thread' => 42];
        $items = [];
        $i = 0;
        foreach ($names as $name => $qty) {
            $p = $this->product('P00' . ++$i, 1000, 60, $name);          // bought at 60 each
            $items[] = $this->line($p, $qty, 100, ['description' => $name]);   // sold at 100, tax left blank = customer's 18%
        }

        $sale = $this->sell($this->header(['gate_pass_no' => '553']), $items);

        $this->assertSame('258', $sale->invoice_no);                       // numbering continues after 257
        $this->assertSame('59100.00', $sale->subtotal);
        $this->assertSame('10638.00', $sale->tax_total);
        $this->assertSame('69738.00', $sale->total);
        $this->assertSame('553', $sale->gate_pass_no);
        $this->assertSame('4266.00', $sale->items[0]->tax_amount);
        $this->assertSame('27966.00', $sale->items[0]->line_total);
        $this->assertSame('4104.00', $sale->items[1]->tax_amount);
        $this->assertSame('26904.00', $sale->items[1]->line_total);
        $this->assertSame(['Multan Fabrics PVT Limited', '1290353-1', '04-90-9999-247-82'], [$sale->buyer_name, $sale->buyer_ntn, $sale->buyer_strn]);

        $this->assertSame('69738.00', $this->customer->ledgerBalance());
        $this->assertSame('-59100.00', $this->gl(A::SALES));
        $this->assertSame('-10638.00', $this->gl(A::OUTPUT_TAX));
        $this->assertSame('35460.00', $this->gl(A::COGS));                 // 591 units x 60
        $this->assertSame('35460.00', $sale->cogs_total);
        $this->assertSame('23640.00', $sale->grossProfit());
        $this->assertSame('264540.00', $this->gl(A::INVENTORY));           // 300,000 - 35,460
        $this->assertSame('unpaid', $sale->paymentStatus());
        $this->assertBooksAgree();
    }

    public function test_cash_sale_goes_to_cash_and_part_payment_splits(): void
    {
        $p = $this->product('P1', 100, 60);

        $cash = $this->sell($this->header(['received_now' => '1180', 'received_into_account_id' => Account::byCode(A::CASH)->id]), [$this->line($p, 10, 100)]);
        $this->assertSame('1180.00', $cash->total);
        $this->assertSame('1180.00', $this->gl(A::CASH));
        $this->assertSame('0.00', $this->customer->ledgerBalance());
        $this->assertSame('paid', $cash->paymentStatus());

        $part = $this->sell($this->header(['received_now' => '500', 'received_into_account_id' => Account::byCode(A::BANK)->id]), [$this->line($p, 10, 100)]);
        $this->assertSame('500.00', $this->gl(A::BANK));
        $this->assertSame('680.00', $this->customer->ledgerBalance());
        $this->assertSame('partial', $part->paymentStatus());
        $this->assertSame('680.00', $part->balanceDue());
        $this->assertBooksAgree();
    }

    public function test_discount_is_booked_separately_and_tax_is_on_the_discounted_amount(): void
    {
        $p = $this->product('P1', 1000, 60);

        $sale = $this->sell($this->header(), [$this->line($p, 100, 100, ['discount' => '500'])]);

        $this->assertSame('10000.00', $sale->subtotal);
        $this->assertSame('500.00', $sale->discount_total);
        $this->assertSame('1710.00', $sale->tax_total);                   // 18% of 9,500
        $this->assertSame('11210.00', $sale->total);
        $this->assertSame('-10000.00', $this->gl(A::SALES));              // gross revenue
        $this->assertSame('500.00', $this->gl(A::SALES_DISCOUNT));        // contra-revenue
        $this->assertSame('11210.00', $this->customer->ledgerBalance());
        $this->assertBooksAgree();
    }

    public function test_tax_rate_defaults_from_the_customer_and_can_be_overridden_per_line(): void
    {
        $calc = fn (array $items, $default) => $this->svc->calculate($items, $default);
        $base = ['product_id' => 1, 'qty' => 1, 'unit_price' => 1000];

        $this->assertSame('180.00', $calc([$base], '18')['lines'][0]['tax_amount']);                       // blank (missing) = customer's rate
        $this->assertSame('180.00', $calc([$base + ['tax_rate' => '']], '18')['lines'][0]['tax_amount']);  // blank string too
        $this->assertSame('0.00', $calc([$base + ['tax_rate' => '0']], '18')['lines'][0]['tax_amount']);   // an explicit 0 is respected
        $this->assertSame('50.00', $calc([$base + ['tax_rate' => '5']], '18')['lines'][0]['tax_amount']);
        $this->assertSame('0.00', $calc([$base], '0')['lines'][0]['tax_amount']);                          // tax-exempt customer

        $exempt = Customer::create(['code' => 'C002', 'name' => 'Exempt Co', 'is_tax_exempt' => true]);
        $p = $this->product('P1', 100, 60);
        $sale = $this->sell($this->header(['customer_id' => $exempt->id]), [$this->line($p, 10, 100)]);
        $this->assertSame('0.00', $sale->tax_total);
        $this->assertSame('1000.00', $sale->total);
    }

    public function test_cogs_uses_the_moving_average_cost(): void
    {
        $p = $this->product('P1', 100, 100);                                        // 100 @ 100
        app(PurchaseService::class)->post(app(PurchaseService::class)->createDraft(
            ['purchase_date' => '2026-10-02', 'paid_now' => '6000', 'paid_from_account_id' => Account::byCode(A::CASH)->id],
            [['product_id' => $p->id, 'qty' => 50, 'unit_cost' => 120]]              // 50 @ 120 -> average 106.6667
        ));

        $sale = $this->sell($this->header(), [$this->line($p, 30, 200)]);

        $this->assertSame('3200.00', $sale->cogs_total);                             // 30 x 106.6667
        $this->assertSame('106.6667', $sale->items[0]->unit_cost);
        $this->assertSame('3200.00', $sale->items[0]->cogs_amount);
        $this->assertSame('120.000', $p->currentStock());
        $this->assertBooksAgree();
    }

    public function test_not_enough_stock_is_refused_leaves_a_draft_and_does_not_burn_an_invoice_number(): void
    {
        $p = $this->product('P1', 10, 50);
        $draft = $this->svc->createDraft($this->header(), [$this->line($p, 11, 100)]);

        try {
            $this->svc->post($draft);
            $this->fail('Expected InsufficientStockException');
        } catch (InsufficientStockException $e) {
            $this->assertStringContainsString('10.000 available', $e->getMessage());
        }

        $this->assertSame('draft', $draft->fresh()->status);
        $this->assertSame('10.000', $p->currentStock());
        $this->assertSame(0, JournalEntry::where('voucher_type', 'sale')->count());
        $this->assertSame('258', $this->sell($this->header(), [$this->line($p, 5, 100)])->invoice_no);   // 258 was not consumed
    }

    public function test_negative_stock_when_enabled_is_costed_at_the_last_average(): void
    {
        $p = $this->product('P1', 10, 50);
        Setting::set('allow_negative_stock', true);

        $sale = $this->sell($this->header(), [$this->line($p, 11, 100)]);

        $this->assertSame('-1.000', $p->currentStock());
        $this->assertSame('550.00', $sale->cogs_total);                              // 10 at book value 500 + 1 at the last average 50
    }

    public function test_credit_limit(): void
    {
        $p = $this->product('P1', 1000, 10);
        $this->customer->update(['credit_limit' => '1000', 'is_tax_exempt' => true]);

        $this->sell($this->header(), [$this->line($p, 6, 100)]);                     // owes 600

        $draft = $this->svc->createDraft($this->header(), [$this->line($p, 5, 100)]);   // +500 would make 1,100 > 1,000
        try {
            $this->svc->post($draft);
            $this->fail('Expected DomainException');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Credit limit exceeded', $e->getMessage());
        }
        $this->assertSame('draft', $draft->fresh()->status);
        $this->assertSame('600.00', $this->customer->ledgerBalance());

        // paying at the counter adds nothing to what they owe, so it passes
        $this->sell($this->header(['received_now' => '500', 'received_into_account_id' => Account::byCode(A::CASH)->id]), [$this->line($p, 5, 100)]);
        $this->assertSame('600.00', $this->customer->ledgerBalance());

        // limit 0 = unlimited
        $this->customer->update(['credit_limit' => '0']);
        $this->svc->post($draft);
        $this->assertSame('1100.00', $this->customer->ledgerBalance());
    }

    public function test_invoice_numbers_are_assigned_at_posting_with_no_gaps(): void
    {
        $p = $this->product('P1', 100, 10);

        $draft = $this->svc->createDraft($this->header(), [$this->line($p, 1, 100)]);
        $this->assertStringStartsWith('DRAFT-', $draft->invoice_no);
        $this->assertFalse($draft->hasInvoiceNumber());

        $a = $this->sell($this->header(), [$this->line($p, 1, 100)]);
        $b = $this->sell($this->header(), [$this->line($p, 1, 100)]);
        $draft->items()->delete();
        $draft->delete();                                                            // a deleted draft leaves no hole
        $this->svc->cancel($b, 'test');                                              // a cancelled invoice keeps its number
        $c = $this->sell($this->header(), [$this->line($p, 1, 100)]);

        $this->assertSame(['258', '259', '260'], [$a->invoice_no, $b->fresh()->invoice_no, $c->invoice_no]);
    }

    public function test_business_rules_are_enforced_and_nothing_is_saved(): void
    {
        $p = $this->product('P1', 100, 10);
        $cases = [
            'received more than total'  => [$this->header(['received_now' => '5000', 'received_into_account_id' => Account::byCode(A::CASH)->id]), [$this->line($p, 1, 100)], 'more than the invoice total'],
            'received with no account'  => [$this->header(['received_now' => '50']), [$this->line($p, 1, 100)], 'cash or bank account'],
            'received into a bad account' => [$this->header(['received_now' => '50', 'received_into_account_id' => Account::byCode(A::SALES)->id]), [$this->line($p, 1, 100)], 'cash or bank account'],
            'discount above amount'     => [$this->header(), [$this->line($p, 1, 100, ['discount' => '150'])], 'discount'],
            'zero quantity'             => [$this->header(), [$this->line($p, 0, 100)], 'quantity'],
            'zero total'                => [$this->header(), [$this->line($p, 1, 0)], 'greater than zero'],
        ];

        foreach ($cases as $label => [$header, $items, $expected]) {
            try {
                $this->svc->createDraft($header, $items);
                $this->fail("Expected DomainException for: {$label}");
            } catch (DomainException $e) {
                $this->assertStringContainsString($expected, $e->getMessage(), $label);
            }
        }
        $this->assertSame(0, Sale::count());
    }

    public function test_drafts_move_nothing_and_are_editable_until_posted(): void
    {
        $p = $this->product('P1', 100, 10);
        $draft = $this->svc->createDraft($this->header(), [$this->line($p, 10, 100)]);

        $this->assertSame('100.000', $p->currentStock());
        $this->assertSame(0, JournalEntry::where('voucher_type', 'sale')->count());

        $draft = $this->svc->updateDraft($draft, $this->header(['gate_pass_no' => '77']), [$this->line($p, 2, 50, ['tax_rate' => '0']), $this->line($p, 1, 25, ['tax_rate' => '0'])]);
        $this->assertSame('125.00', $draft->total);
        $this->assertCount(2, $draft->items);

        $posted = $this->svc->post($draft);
        try {
            $this->svc->updateDraft($posted, $this->header(), [$this->line($p, 1, 1)]);
            $this->fail('Expected DomainException');
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(DomainException::class);
        $posted->update(['notes' => 'edited after posting']);
    }

    public function test_cancelling_restores_stock_at_original_cost_and_keeps_the_invoice(): void
    {
        $p = $this->product('P1', 100, 60);
        $sale = $this->sell($this->header(['received_now' => '200', 'received_into_account_id' => Account::byCode(A::BANK)->id]), [$this->line($p, 10, 100)]);

        $sale = $this->svc->cancel($sale, 'wrong customer');

        $this->assertSame('cancelled', $sale->status);
        $this->assertSame('258', $sale->invoice_no);
        $this->assertNotNull($sale->reversal_entry_id);
        $this->assertSame('100.000', $p->currentStock());
        $this->assertSame('6000.00', $p->stockValue());
        $this->assertSame('0.00', $this->customer->ledgerBalance());
        $this->assertSame('0.00', $this->gl(A::SALES));
        $this->assertSame('0.00', $this->gl(A::COGS));
        $this->assertSame('0.00', $this->gl(A::BANK));
        $this->assertSame('cancelled', $sale->paymentStatus());
        $this->assertNotNull(Sale::find($sale->id));
        $this->assertBooksAgree();

        $this->expectException(DomainException::class);
        $this->svc->cancel($sale, 'again');
    }

    public function test_cannot_cancel_while_receipts_are_applied(): void
    {
        $p = $this->product('P1', 100, 60);
        $sale = $this->sell($this->header(), [$this->line($p, 10, 100)]);
        PaymentAllocation::create(['document_type' => 'receipt', 'document_id' => 1, 'target_type' => 'sale', 'target_id' => $sale->id, 'amount' => '400', 'is_active' => true]);

        $this->assertSame('partial', $sale->paymentStatus());
        $this->assertSame('780.00', $sale->balanceDue());                          // 1,180 - 400

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Receipts have been applied');
        $this->svc->cancel($sale, 'x');
    }
}
