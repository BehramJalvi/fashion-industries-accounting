<?php

namespace Tests\Feature;

use App\Exceptions\Inventory\InsufficientStockException;
use App\Models\Account;
use App\Models\Customer;
use App\Models\InventoryTransaction;
use App\Models\JournalEntryLine;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SalesReturn;
use App\Models\Unit;
use App\Services\Inventory\OpeningStockService;
use App\Services\Purchasing\PurchaseService;
use App\Services\Sales\SaleReturnService;
use App\Services\Sales\SaleService;
use App\Support\AccountCodes as A;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\FiscalYearSeeder;
use Database\Seeders\NumberSequenceSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SaleReturnServiceTest extends TestCase
{
    use RefreshDatabase;

    private SaleService $sales;

    private SaleReturnService $returns;

    private Customer $customer;

    private Product $thread;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([FiscalYearSeeder::class, ChartOfAccountsSeeder::class, NumberSequenceSeeder::class]);
        $this->sales = app(SaleService::class);
        $this->returns = app(SaleReturnService::class);
        $this->customer = Customer::create(['code' => 'C001', 'name' => 'Multan Fabrics PVT Limited']);   // 18% by default
        $this->thread = $this->stocked('P1', 1000, 60);
    }

    private function stocked(string $code, string|int $qty, string|float $cost): Product
    {
        $p = Product::create(['code' => $code, 'name' => "Thread {$code}", 'unit_id' => Unit::firstOrCreate(['name' => 'Bundle'])->id, 'product_type' => 'finished_good', 'purchase_price' => 0, 'selling_price' => 100]);
        app(OpeningStockService::class)->record($p, $qty, $cost, '2026-10-01');

        return $p;
    }

    private function sell(string|int $qty, string|float $price, array $header = [], array $itemExtra = [], ?Product $product = null): Sale
    {
        return $this->sales->post($this->sales->createDraft(
            array_merge(['customer_id' => $this->customer->id, 'sale_date' => '2026-10-05'], $header),
            [array_merge(['product_id' => ($product ?? $this->thread)->id, 'qty' => $qty, 'unit_price' => $price], $itemExtra)]
        ));
    }

    private function giveBack(Sale $sale, string|int $qty, array $header = [], ?Product $product = null): SalesReturn
    {
        return $this->returns->createDraft($sale->fresh('items'), array_merge(['return_date' => $sale->sale_date->toDateString()], $header), [($product ?? $this->thread)->id => $qty]);
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

        $ledger = InventoryTransaction::whereIn('id', InventoryTransaction::query()->selectRaw('MAX(id)')->groupBy('product_id'))->get()->sum(fn ($r) => (float) $r->balance_value);
        $this->assertSame(number_format($ledger, 2, '.', ''), $this->gl(A::INVENTORY));
        $this->assertSame($this->customer->ledgerBalance(), $this->gl(A::RECEIVABLE));
    }

    public function test_credit_note_return_reverses_revenue_tax_cost_and_receivable(): void
    {
        $sale = $this->sell(100, 100);                                  // net 10,000 + tax 1,800 = 11,800; cost 6,000

        $r = $this->returns->post($this->giveBack($sale, 30));

        $this->assertSame('3000.00', $r->subtotal);
        $this->assertSame('540.00', $r->tax_total);
        $this->assertSame('3540.00', $r->total);
        $this->assertSame('SRT-0001', $r->return_no);
        $this->assertSame('8260.00', $this->customer->ledgerBalance());   // 11,800 - 3,540
        $this->assertSame('930.000', $this->thread->currentStock());       // 1000 - 100 + 30
        $this->assertSame('55800.00', $this->thread->stockValue());        // 60,000 - 6,000 + 1,800 (back at the cost it left at)
        $this->assertSame('3000.00', $this->gl(A::SALES_RETURNS));
        $this->assertSame('-1260.00', $this->gl(A::OUTPUT_TAX));           // 1,800 collected - 540 reversed
        $this->assertSame('4200.00', $this->gl(A::COGS));                  // 6,000 - 1,800
        $this->assertBooksAgree();
    }

    public function test_discount_and_tax_come_back_in_proportion(): void
    {
        $sale = $this->sell(100, 100, [], ['discount' => '500']);         // net 9,500 + tax 1,710 = 11,210

        $r = $this->returns->post($this->giveBack($sale, 40));

        $this->assertSame('3800.00', $r->subtotal);
        $this->assertSame('684.00', $r->tax_total);
        $this->assertSame('4484.00', $r->total);
        $this->assertSame('6726.00', $this->customer->ledgerBalance());   // 11,210 - 4,484
        $this->assertBooksAgree();
    }

    public function test_repeated_partial_returns_never_lose_or_invent_a_paisa(): void
    {
        $odd = $this->stocked('P2', 3, '3.3333');                          // 3 units costing exactly 10.00
        $this->customer->update(['is_tax_exempt' => true]);
        $sale = $this->sell(3, 10, [], [], $odd);                          // sold for 30.00, cost 10.00

        $totals = [];
        foreach ([1, 1, 1] as $q) {
            $totals[] = $this->returns->post($this->giveBack($sale, $q, [], $odd))->total;
        }

        $this->assertSame(['10.00', '10.00', '10.00'], $totals);
        $this->assertSame('0.00', $this->customer->ledgerBalance());
        $this->assertSame('0.00', $this->gl(A::COGS));                      // cost 3.33 + 3.34 + 3.33 came back exactly
        $this->assertSame('3.000', $odd->currentStock());
        $this->assertSame('10.00', $odd->stockValue());
        $this->assertBooksAgree();
    }

    public function test_goods_return_at_the_cost_they_left_at_even_if_the_average_has_moved(): void
    {
        $sale = $this->sell(10, 100);                                       // left at 60 each = 600
        app(PurchaseService::class)->post(app(PurchaseService::class)->createDraft(
            ['purchase_date' => '2026-10-06', 'paid_now' => '10000', 'paid_from_account_id' => Account::byCode(A::CASH)->id],
            [['product_id' => $this->thread->id, 'qty' => 100, 'unit_cost' => 100]]       // average cost drifts upward
        ));

        $this->returns->post($this->giveBack($sale, 5, ['return_date' => '2026-10-07']));

        $row = InventoryTransaction::where('txn_type', 'sale_return')->firstOrFail();
        $this->assertSame('300.00', $row->total_cost);                      // 5 x 60, not 5 x the new average
        $this->assertBooksAgree();
    }

    public function test_cannot_return_more_than_was_sold(): void
    {
        $sale = $this->sell(100, 100);
        $this->returns->post($this->giveBack($sale, 60));

        try {
            $this->giveBack($sale, 50);
            $this->fail('Expected DomainException');
        } catch (DomainException $e) {
            $this->assertStringContainsString('40.000 left to return', $e->getMessage());
        }
        $this->assertSame(1, SalesReturn::count());
    }

    public function test_return_date_and_refund_rules(): void
    {
        $sale = $this->sell(10, 100);
        $cases = [
            'before the invoice' => [['return_date' => '2026-10-01'], 'before the invoice date'],
            'refund above value' => [['refund_now' => '5000', 'refund_account_id' => Account::byCode(A::CASH)->id], 'more than the value'],
            'refund without an account' => [['refund_now' => '100'], 'cash or bank account'],
        ];
        foreach ($cases as $label => [$header, $expected]) {
            try {
                $this->giveBack($sale, 1, $header);
                $this->fail("Expected DomainException for: {$label}");
            } catch (DomainException $e) {
                $this->assertStringContainsString($expected, $e->getMessage(), $label);
            }
        }
        $this->assertSame(0, SalesReturn::count());
    }

    public function test_cash_refund_to_a_customer_who_paid_in_cash(): void
    {
        $cash = Account::byCode(A::CASH)->id;
        $sale = $this->sell(10, 100, ['received_now' => '1180', 'received_into_account_id' => $cash]);      // 1,180 in

        $this->returns->post($this->giveBack($sale, 5, ['refund_now' => '590', 'refund_account_id' => $cash]));   // 590 out

        $this->assertSame('590.00', $this->gl(A::CASH));
        $this->assertSame('0.00', $this->customer->ledgerBalance());
        $summary = $sale->fresh()->summary();
        $this->assertSame(0, $summary['balance']);
        $this->assertSame('paid', $summary['status']);
        $this->assertBooksAgree();
    }

    public function test_part_refund_part_credit_note_and_sale_balance_matches_the_customer_ledger(): void
    {
        $this->customer->update(['is_tax_exempt' => true]);
        $sale = $this->sell(100, 100);                                       // owes 10,000

        $this->returns->post($this->giveBack($sale, 40, ['refund_now' => '1000', 'refund_account_id' => Account::byCode(A::BANK)->id]));

        $this->assertSame('7000.00', $this->customer->ledgerBalance());     // 10,000 - 3,000 credit note
        $this->assertSame('7000.00', $sale->fresh()->balanceDue());         // 10,000 - 4,000 + 1,000 refunded
        $this->assertSame('-1000.00', $this->gl(A::BANK));                  // refund paid out
        $this->assertBooksAgree();
    }

    public function test_cancelling_a_return_restores_everything(): void
    {
        $sale = $this->sell(100, 100);
        $r = $this->returns->post($this->giveBack($sale, 30));

        $r = $this->returns->cancel($r, 'customer kept the goods');

        $this->assertSame('cancelled', $r->status);
        $this->assertSame('11800.00', $this->customer->ledgerBalance());
        $this->assertSame('900.000', $this->thread->currentStock());
        $this->assertSame('54000.00', $this->thread->stockValue());
        $this->assertSame(0, $sale->fresh()->summary()['returned']);
        $this->assertBooksAgree();

        $this->returns->post($this->giveBack($sale, 100));                    // all 100 are returnable again
        $this->assertSame('1000.000', $this->thread->currentStock());
    }

    public function test_a_return_cannot_be_cancelled_once_the_goods_were_sold_again(): void
    {
        $p = $this->stocked('P3', 10, 60);
        $this->customer->update(['is_tax_exempt' => true]);
        $first = $this->sell(10, 100, [], [], $p);
        $r = $this->returns->post($this->giveBack($first, 10, [], $p));        // stock 10 again
        $this->sell(10, 100, ['sale_date' => '2026-10-11'], [], $p);            // sold again: stock 0

        try {
            $this->returns->cancel($r, 'x');
            $this->fail('Expected InsufficientStockException');
        } catch (InsufficientStockException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame('posted', $r->fresh()->status);
        $this->assertBooksAgree();
    }

    public function test_an_invoice_with_returns_cannot_be_cancelled_until_the_returns_are(): void
    {
        $sale = $this->sell(10, 100);
        $r = $this->returns->post($this->giveBack($sale, 2));

        try {
            $this->sales->cancel($sale, 'x');
            $this->fail('Expected DomainException');
        } catch (DomainException $e) {
            $this->assertStringContainsString('has sales returns', $e->getMessage());
        }

        $this->returns->cancel($r, 'undo');
        $this->sales->cancel($sale, 'now fine');
        $this->assertSame('cancelled', $sale->fresh()->status);
        $this->assertSame('1000.000', $this->thread->currentStock());
        $this->assertBooksAgree();
    }

    public function test_returns_only_against_posted_invoices_and_posted_returns_are_locked(): void
    {
        $draft = $this->sales->createDraft(['customer_id' => $this->customer->id, 'sale_date' => '2026-10-05'], [['product_id' => $this->thread->id, 'qty' => 5, 'unit_price' => 10]]);
        try {
            $this->returns->createDraft($draft->load('items'), ['return_date' => '2026-10-10'], [$this->thread->id => 1]);
            $this->fail('Expected DomainException');
        } catch (DomainException $e) {
            $this->assertStringContainsString('posted invoice', $e->getMessage());
        }

        $sale = $this->sell(10, 100);
        $r = $this->returns->post($this->giveBack($sale, 2));

        $this->expectException(DomainException::class);
        $r->update(['reason' => 'edited']);
    }
}
