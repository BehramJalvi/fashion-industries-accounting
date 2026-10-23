<?php

namespace Tests\Feature;

use App\Exceptions\Inventory\InsufficientStockException;
use App\Models\Account;
use App\Models\Employee;
use App\Models\InventoryTransaction;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Supplier;
use App\Models\Unit;
use App\Services\Inventory\InventoryService;
use App\Services\Purchasing\PurchaseReturnService;
use App\Services\Purchasing\PurchaseService;
use App\Support\AccountCodes as A;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\FiscalYearSeeder;
use Database\Seeders\NumberSequenceSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseReturnServiceTest extends TestCase
{
    use RefreshDatabase;

    private PurchaseService $purchases;

    private PurchaseReturnService $returns;

    private Product $yarn;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([FiscalYearSeeder::class, ChartOfAccountsSeeder::class, NumberSequenceSeeder::class]);
        $this->purchases = app(PurchaseService::class);
        $this->returns = app(PurchaseReturnService::class);
        $this->yarn = Product::create(['code' => 'Y1', 'name' => 'Cotton Yarn', 'unit_id' => Unit::create(['name' => 'Bundle'])->id, 'product_type' => 'raw_material', 'purchase_price' => 0, 'selling_price' => 0]);
        $this->supplier = Supplier::create(['code' => 'S001', 'name' => 'Al Zamin']);
    }

    private function buy(string|int $qty, string|float $cost, array $header = [], array $itemExtra = []): Purchase
    {
        return $this->purchases->post($this->purchases->createDraft(
            array_merge(['purchase_date' => '2026-10-05', 'supplier_id' => $this->supplier->id], $header),
            [array_merge(['product_id' => $this->yarn->id, 'qty' => $qty, 'unit_cost' => $cost], $itemExtra)]
        ));
    }

    private function giveBack(Purchase $p, string|int $qty, array $header = []): PurchaseReturn
    {
        return $this->returns->createDraft($p->fresh('items'), array_merge(['return_date' => $p->purchase_date->toDateString()], $header), [$this->yarn->id => $qty]);
    }

    private function gl(string $code): string
    {
        $r = JournalEntryLine::join('accounts as a', 'a.id', '=', 'journal_entry_lines.account_id')->where('a.code', $code)
            ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) as bal')->value('bal');

        return number_format((float) $r, 2, '.', '');
    }

    private function ledgerValue(): string
    {
        return number_format((float) (InventoryTransaction::where('product_id', $this->yarn->id)->orderByDesc('id')->value('balance_value') ?? 0), 2, '.', '');
    }

    private function assertBooksAgree(): void
    {
        $this->assertSame((string) JournalEntryLine::sum('debit'), (string) JournalEntryLine::sum('credit'));
        $this->assertSame($this->ledgerValue(), $this->gl(A::INVENTORY));
    }

    public function test_credit_note_return_reduces_stock_inventory_value_and_what_we_owe(): void
    {
        $p = $this->buy(100, '100');                                    // owe 10,000

        $r = $this->returns->post($this->giveBack($p, 30));

        $this->assertSame('3000.00', $r->total);
        $this->assertSame('70.000', $this->yarn->currentStock());
        $this->assertSame('7000.00', $this->yarn->stockValue());
        $this->assertSame('7000.00', $this->supplier->ledgerBalance());
        $this->assertSame('PRT-0001', $r->return_no);
        $this->assertSame('1', (string) JournalEntry::where('voucher_type', 'purchase_return')->count());
        $this->assertBooksAgree();
    }

    public function test_purchase_balance_equals_the_supplier_ledger(): void
    {
        $p = $this->buy(100, '100');
        $this->returns->post($this->giveBack($p, 40, ['refund_now' => '1000', 'refund_account_id' => Account::byCode(A::BANK)->id]));

        $summary = $p->fresh()->summary();
        $this->assertSame(['returned' => 400000, 'refunded' => 100000, 'balance' => 700000], ['returned' => $summary['returned'], 'refunded' => $summary['refunded'], 'balance' => $summary['balance']]);
        $this->assertSame('7000.00', $this->supplier->ledgerBalance());   // 10,000 - 3,000 credit note
        $this->assertSame('1000.00', $this->gl(A::BANK));                  // refund received
        $this->assertSame('unpaid', $summary['status']);
        $this->assertBooksAgree();
    }

    public function test_returned_value_includes_the_proportion_of_discount_and_input_tax(): void
    {
        $p = $this->buy(100, '100', [], ['discount' => '500', 'tax_rate' => '18']);   // net 9,500 + tax 1,710 = 11,210

        $r = $this->returns->post($this->giveBack($p, 40));

        $this->assertSame('3800.00', $r->subtotal);
        $this->assertSame('684.00', $r->tax_total);
        $this->assertSame('4484.00', $r->total);
        $this->assertSame('6726.00', $this->supplier->ledgerBalance());      // 11,210 - 4,484
        $this->assertSame('5700.00', $this->yarn->stockValue());
        $this->assertSame('1026.00', $this->gl(A::INPUT_TAX));               // 1,710 - 684 still reclaimable
        $this->assertBooksAgree();
    }

    public function test_repeated_partial_returns_never_lose_or_invent_a_paisa(): void
    {
        $p = $this->buy(3, '3.3333');                                         // 3 units for exactly 10.00

        $totals = [];
        foreach ([1, 1, 1] as $q) {
            $totals[] = $this->returns->post($this->giveBack($p, $q))->total;
        }

        $this->assertSame(['3.33', '3.34', '3.33'], $totals);
        $this->assertSame('0.00', $this->supplier->ledgerBalance());
        $this->assertSame('0.000', $this->yarn->currentStock());
        $this->assertSame('0.00', $this->gl(A::INVENTORY));
        $this->assertBooksAgree();
    }

    public function test_cannot_return_more_than_was_bought(): void
    {
        $p = $this->buy(100, '100');
        $this->returns->post($this->giveBack($p, 60));

        try {
            $this->giveBack($p, 50);
            $this->fail('Expected DomainException');
        } catch (DomainException $e) {
            $this->assertStringContainsString('40.000 left to return', $e->getMessage());
        }
        $this->assertSame(1, PurchaseReturn::count());
    }

    public function test_return_dates_and_refund_rules(): void
    {
        $p = $this->buy(10, '100');

        $cases = [
            'before the purchase' => [['return_date' => '2026-10-01'], 'before the purchase date'],
            'refund above value' => [['refund_now' => '5000', 'refund_account_id' => Account::byCode(A::CASH)->id], 'more than the value'],
            'refund without an account' => [['refund_now' => '100'], 'cash or bank account'],
        ];
        foreach ($cases as $label => [$header, $expected]) {
            try {
                $this->giveBack($p, 1, $header);
                $this->fail("Expected DomainException for: {$label}");
            } catch (DomainException $e) {
                $this->assertStringContainsString($expected, $e->getMessage(), $label);
            }
        }
        $this->assertSame(0, PurchaseReturn::count());
    }

    public function test_walk_in_cash_purchase_must_be_refunded_in_full(): void
    {
        $cash = Account::byCode(A::CASH)->id;
        $p = $this->buy(10, '100', ['supplier_id' => null, 'paid_now' => '1000', 'paid_from_account_id' => $cash]);

        try {
            $this->giveBack($p, 5);                                              // no refund given: nobody to credit
            $this->fail('Expected DomainException');
        } catch (DomainException $e) {
            $this->assertStringContainsString('refunded in full', $e->getMessage());
        }

        $this->returns->post($this->giveBack($p, 5, ['refund_now' => '500', 'refund_account_id' => $cash]));

        $this->assertSame('-500.00', $this->gl(A::CASH));                        // 1,000 paid, 500 refunded
        $this->assertSame('500.00', $this->yarn->stockValue());
        $this->assertBooksAgree();
    }

    public function test_return_against_an_employee_funded_purchase_reduces_what_we_owe_the_employee(): void
    {
        $mohsin = Employee::create(['code' => 'E001', 'name' => 'Mohsin']);
        $p = $this->buy(10, '60', ['supplier_id' => null, 'funded_by_employee_id' => $mohsin->id]);   // owe Mohsin 600

        $this->returns->post($this->giveBack($p, 5));

        $this->assertSame('300.00', $mohsin->ledgerBalance());
        $this->assertBooksAgree();
    }

    public function test_stock_that_was_already_sold_cannot_go_back_and_nothing_is_posted(): void
    {
        $p = $this->buy(10, '100');
        app(InventoryService::class)->issue($this->yarn, 8, 'sale', '2026-10-06');
        $draft = $this->giveBack($p, 5);

        try {
            $this->returns->post($draft);
            $this->fail('Expected InsufficientStockException');
        } catch (InsufficientStockException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame('draft', $draft->fresh()->status);
        $this->assertSame('1000.00', $this->supplier->ledgerBalance());
        $this->assertSame('2.000', $this->yarn->currentStock());
    }

    public function test_cancelling_a_return_restores_stock_and_balance(): void
    {
        $p = $this->buy(100, '100');
        $r = $this->returns->post($this->giveBack($p, 30));

        $r = $this->returns->cancel($r, 'supplier refused');

        $this->assertSame('cancelled', $r->status);
        $this->assertSame('100.000', $this->yarn->currentStock());
        $this->assertSame('10000.00', $this->yarn->stockValue());
        $this->assertSame('10000.00', $this->supplier->ledgerBalance());
        $this->assertSame('0', (string) $p->fresh()->summary()['returned']);
        $this->assertBooksAgree();

        $this->returns->post($this->giveBack($p, 100));                           // the full quantity is returnable again
        $this->assertSame('0.000', $this->yarn->currentStock());
    }

    public function test_returns_only_against_posted_purchases_and_a_purchase_with_returns_cannot_be_cancelled(): void
    {
        $draft = $this->purchases->createDraft(['purchase_date' => '2026-10-05', 'supplier_id' => $this->supplier->id], [['product_id' => $this->yarn->id, 'qty' => 5, 'unit_cost' => 10]]);
        try {
            $this->returns->createDraft($draft->load('items'), ['return_date' => '2026-10-10'], [$this->yarn->id => 1]);
            $this->fail('Expected DomainException');
        } catch (DomainException $e) {
            $this->assertStringContainsString('posted purchase', $e->getMessage());
        }

        $p = $this->buy(10, '100');
        $r = $this->returns->post($this->giveBack($p, 2));
        try {
            $this->purchases->cancel($p, 'x');
            $this->fail('Expected DomainException');
        } catch (DomainException $e) {
            $this->assertStringContainsString('has returns', $e->getMessage());
        }

        $this->returns->cancel($r, 'undo');
        $this->purchases->cancel($p, 'now fine');
        $this->assertSame('cancelled', $p->fresh()->status);
        $this->assertBooksAgree();
    }

    public function test_a_posted_return_is_locked(): void
    {
        $p = $this->buy(10, '100');
        $r = $this->returns->post($this->giveBack($p, 2));

        $this->expectException(DomainException::class);
        $r->update(['reason' => 'edited']);
    }
}
