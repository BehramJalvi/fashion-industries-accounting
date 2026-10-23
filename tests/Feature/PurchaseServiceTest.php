<?php

namespace Tests\Feature;

use App\Exceptions\Inventory\InsufficientStockException;
use App\Models\Account;
use App\Models\Employee;
use App\Models\InventoryTransaction;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\PaymentAllocation;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\Unit;
use App\Services\Inventory\InventoryService;
use App\Services\Purchasing\PurchaseService;
use App\Support\AccountCodes as A;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\FiscalYearSeeder;
use Database\Seeders\NumberSequenceSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseServiceTest extends TestCase
{
    use RefreshDatabase;

    private PurchaseService $svc;
    private Product $yarn;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([FiscalYearSeeder::class, ChartOfAccountsSeeder::class, NumberSequenceSeeder::class]);
        $this->svc = app(PurchaseService::class);
        $this->yarn = Product::create(['code' => 'Y1', 'name' => 'Cotton Yarn', 'unit_id' => Unit::create(['name' => 'Bundle'])->id, 'product_type' => 'raw_material', 'purchase_price' => 0, 'selling_price' => 0]);
    }

    private function header(array $over = []): array
    {
        return array_merge(['purchase_date' => '2026-10-05'], $over);
    }

    private function item(string|int|float $qty, string|int|float $cost, array $over = []): array
    {
        return array_merge(['product_id' => $this->yarn->id, 'qty' => $qty, 'unit_cost' => $cost], $over);
    }

    private function postPurchase(array $header, array $items): Purchase
    {
        return $this->svc->post($this->svc->createDraft($header, $items));
    }

    private function gl(string $code): string
    {
        $r = JournalEntryLine::join('accounts as a', 'a.id', '=', 'journal_entry_lines.account_id')->where('a.code', $code)
            ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) as bal')->value('bal');

        return number_format((float) $r, 2, '.', '');
    }

    private function ledgerValue(): string
    {
        return number_format((float) InventoryTransaction::where('product_id', $this->yarn->id)->orderByDesc('id')->value('balance_value'), 2, '.', '');
    }

    /** Every purchase scenario must keep: journal balanced, and stock-ledger value == Inventory account. */
    private function assertBooksAgree(): void
    {
        $this->assertSame((string) JournalEntryLine::sum('debit'), (string) JournalEntryLine::sum('credit'));
        $this->assertSame($this->ledgerValue(), $this->gl(A::INVENTORY));
    }

    private function cash(): int { return Account::byCode(A::CASH)->id; }
    private function bank(): int { return Account::byCode(A::BANK)->id; }

    public function test_walk_in_cash_purchase_needs_no_supplier(): void
    {
        // "72 bundles for 5,000", paid in cash, nobody to owe
        $p = $this->postPurchase($this->header(['paid_now' => '5000', 'paid_from_account_id' => $this->cash()]), [$this->item(72, '69.4444')]);

        $this->assertSame('5000.00', $p->total);
        $this->assertSame('72.000', $this->yarn->currentStock());
        $this->assertSame('5000.00', $this->yarn->stockValue());
        $this->assertSame('69.4444', $this->yarn->averageCost());
        $this->assertSame('-5000.00', $this->gl(A::CASH));
        $this->assertSame('0.00', $this->gl(A::PAYABLE));
        $this->assertSame('paid', $p->paymentStatus());
        $this->assertSame('PUR-0001', $p->purchase_no);
        $this->assertBooksAgree();
    }

    public function test_credit_purchase_from_supplier_increases_payable_not_cash(): void
    {
        $alZamin = Supplier::create(['code' => 'S001', 'name' => 'Al Zamin', 'payment_terms_days' => 30]);

        $p = $this->postPurchase($this->header(['supplier_id' => $alZamin->id, 'supplier_ref' => 'BILL-001']), [$this->item(100, '1953')]);

        $this->assertSame('195300.00', $alZamin->ledgerBalance());
        $this->assertSame('0.00', $this->gl(A::CASH));
        $this->assertSame('195300.00', $this->gl(A::INVENTORY));
        $this->assertSame('unpaid', $p->paymentStatus());
        $this->assertSame('195300.00', $p->balanceDue());
        $this->assertSame('2026-11-04', $p->due_date->toDateString());      // purchase date + the supplier's 30 days
        $this->assertSame(1, JournalEntry::where('voucher_type', 'purchase')->where('reference', 'BILL-001')->count());
        $this->assertBooksAgree();
    }

    public function test_part_paid_purchase_splits_between_bank_and_payable(): void
    {
        $alZamin = Supplier::create(['code' => 'S001', 'name' => 'Al Zamin']);

        $p = $this->postPurchase($this->header(['supplier_id' => $alZamin->id, 'paid_now' => '50000', 'paid_from_account_id' => $this->bank()]), [$this->item(100, '1953')]);

        $this->assertSame('145300.00', $alZamin->ledgerBalance());
        $this->assertSame('-50000.00', $this->gl(A::BANK));
        $this->assertSame('partial', $p->paymentStatus());
        $this->assertSame('50000.00', $p->paidAmount());
        $this->assertSame('145300.00', $p->balanceDue());
        $this->assertBooksAgree();
    }

    public function test_discount_reduces_stock_cost_and_input_tax_is_booked_separately(): void
    {
        $alZamin = Supplier::create(['code' => 'S001', 'name' => 'Al Zamin']);

        $p = $this->postPurchase($this->header(['supplier_id' => $alZamin->id]), [$this->item(100, '100', ['discount' => '500', 'tax_rate' => '18'])]);

        $this->assertSame('10000.00', $p->subtotal);
        $this->assertSame('500.00', $p->discount_total);
        $this->assertSame('1710.00', $p->tax_total);
        $this->assertSame('11210.00', $p->total);
        $this->assertSame('9500.00', $this->yarn->stockValue());              // net of discount, tax excluded
        $this->assertSame('95.0000', $this->yarn->averageCost());
        $this->assertSame('1710.00', $this->gl(A::INPUT_TAX));
        $this->assertSame('11210.00', $alZamin->ledgerBalance());
        $this->assertBooksAgree();
    }

    public function test_purchase_an_employee_paid_for_makes_the_business_owe_the_employee(): void
    {
        $mohsin = Employee::create(['code' => 'E001', 'name' => 'Mohsin']);

        $p = $this->postPurchase($this->header(['funded_by_employee_id' => $mohsin->id, 'supplier_ref' => 'BILL-002']), [$this->item(1, '600')]);

        $this->assertSame('600.00', $mohsin->ledgerBalance());
        $this->assertSame('0.00', $this->gl(A::PAYABLE));
        $this->assertSame('unpaid', $p->paymentStatus());
        $this->assertSame('600.00', $this->yarn->stockValue());
        $this->assertBooksAgree();
    }

    public function test_weighted_average_across_purchases(): void
    {
        $s = Supplier::create(['code' => 'S001', 'name' => 'Al Zamin']);
        $this->postPurchase($this->header(['supplier_id' => $s->id]), [$this->item(100, '100')]);
        $this->postPurchase($this->header(['supplier_id' => $s->id, 'purchase_date' => '2026-10-06']), [$this->item(50, '120')]);

        $this->assertSame('150.000', $this->yarn->currentStock());
        $this->assertSame('16000.00', $this->yarn->stockValue());
        $this->assertSame('106.6667', $this->yarn->averageCost());
        $this->assertBooksAgree();
    }

    public function test_business_rules_are_enforced_and_nothing_is_saved(): void
    {
        $s = Supplier::create(['code' => 'S001', 'name' => 'Al Zamin']);
        $cases = [
            'credit with nobody to owe' => [$this->header(), [$this->item(1, 100)], 'needs a supplier'],
            'paid more than total'      => [$this->header(['paid_now' => '200', 'paid_from_account_id' => $this->cash()]), [$this->item(1, 100)], 'more than the purchase total'],
            'paid with no account'      => [$this->header(['supplier_id' => $s->id, 'paid_now' => '50']), [$this->item(1, 100)], 'cash or bank account'],
            'paid from a non cash account' => [$this->header(['supplier_id' => $s->id, 'paid_now' => '50', 'paid_from_account_id' => Account::byCode(A::SALES)->id]), [$this->item(1, 100)], 'cash or bank account'],
            'discount above amount'     => [$this->header(['supplier_id' => $s->id]), [$this->item(1, 100, ['discount' => '150'])], 'discount'],
            'zero quantity'             => [$this->header(['supplier_id' => $s->id]), [$this->item(0, 100)], 'quantity'],
            'zero total'                => [$this->header(['supplier_id' => $s->id]), [$this->item(1, 0)], 'greater than zero'],
        ];

        foreach ($cases as $label => [$header, $items, $expected]) {
            try {
                $this->svc->createDraft($header, $items);
                $this->fail("Expected DomainException for: {$label}");
            } catch (DomainException $e) {
                $this->assertStringContainsString($expected, $e->getMessage(), $label);
            }
        }
        $this->assertSame(0, Purchase::count());
    }

    public function test_the_same_supplier_invoice_cannot_be_entered_twice(): void
    {
        $a = Supplier::create(['code' => 'S001', 'name' => 'Al Zamin']);
        $b = Supplier::create(['code' => 'S002', 'name' => 'Other Mills']);
        $first = $this->postPurchase($this->header(['supplier_id' => $a->id, 'supplier_ref' => 'INV-77']), [$this->item(1, 100)]);

        try {
            $this->svc->createDraft($this->header(['supplier_id' => $a->id, 'supplier_ref' => 'INV-77']), [$this->item(1, 100)]);
            $this->fail('Expected DomainException');
        } catch (DomainException $e) {
            $this->assertStringContainsString($first->purchase_no, $e->getMessage());
        }

        $this->svc->createDraft($this->header(['supplier_id' => $b->id, 'supplier_ref' => 'INV-77']), [$this->item(1, 100)]);   // another supplier: fine

        $this->svc->cancel($first, 'duplicate');
        $this->svc->createDraft($this->header(['supplier_id' => $a->id, 'supplier_ref' => 'INV-77']), [$this->item(1, 100)]);     // after cancelling: fine
        $this->addToAssertionCount(1);
    }

    public function test_drafts_move_no_stock_and_can_be_edited_until_posted(): void
    {
        $s = Supplier::create(['code' => 'S001', 'name' => 'Al Zamin']);
        $draft = $this->svc->createDraft($this->header(['supplier_id' => $s->id]), [$this->item(10, 100)]);

        $this->assertSame('draft', $draft->status);
        $this->assertSame('0.000', $this->yarn->currentStock());
        $this->assertSame(0, JournalEntry::count());

        $draft = $this->svc->updateDraft($draft, $this->header(['supplier_id' => $s->id]), [$this->item(20, 50), $this->item(5, 10)]);
        $this->assertSame('1050.00', $draft->total);
        $this->assertCount(2, $draft->items);

        $posted = $this->svc->post($draft);
        $this->assertSame('25.000', $this->yarn->currentStock());

        try {
            $this->svc->updateDraft($posted, $this->header(['supplier_id' => $s->id]), [$this->item(1, 1)]);
            $this->fail('Expected DomainException');
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }
        $this->expectException(DomainException::class);
        $posted->update(['notes' => 'edited after posting']);
    }

    public function test_cancelling_reverses_stock_and_accounts_and_keeps_the_record(): void
    {
        $s = Supplier::create(['code' => 'S001', 'name' => 'Al Zamin']);
        $p = $this->postPurchase($this->header(['supplier_id' => $s->id, 'paid_now' => '1000', 'paid_from_account_id' => $this->bank()]), [$this->item(100, '100')]);

        $p = $this->svc->cancel($p, 'wrong supplier');

        $this->assertSame('cancelled', $p->status);
        $this->assertNotNull($p->reversal_entry_id);
        $this->assertSame('0.000', $this->yarn->currentStock());
        $this->assertSame('0.00', $s->ledgerBalance());
        $this->assertSame('0.00', $this->gl(A::INVENTORY));
        $this->assertSame('0.00', $this->gl(A::BANK));
        $this->assertSame('cancelled', $p->paymentStatus());
        $this->assertSame(1, InventoryTransaction::where('txn_type', 'reversal')->count());
        $this->assertNotNull(Purchase::find($p->id));                       // kept for the audit trail
        $this->assertBooksAgree();

        $this->expectException(DomainException::class);
        $this->svc->cancel($p, 'again');
    }

    public function test_cannot_cancel_a_purchase_whose_stock_was_already_used(): void
    {
        $s = Supplier::create(['code' => 'S001', 'name' => 'Al Zamin']);
        $p = $this->postPurchase($this->header(['supplier_id' => $s->id]), [$this->item(10, '100')]);
        app(InventoryService::class)->issue($this->yarn, 8, 'sale', '2026-10-06');

        try {
            $this->svc->cancel($p, 'oops');
            $this->fail('Expected InsufficientStockException');
        } catch (InsufficientStockException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame('posted', $p->fresh()->status);
        $this->assertSame('1000.00', $s->ledgerBalance());
        $this->assertSame(0, JournalEntry::where('voucher_type', 'reversal')->count());   // rolled back completely
    }

    public function test_cannot_cancel_while_payments_are_applied(): void
    {
        $s = Supplier::create(['code' => 'S001', 'name' => 'Al Zamin']);
        $p = $this->postPurchase($this->header(['supplier_id' => $s->id]), [$this->item(10, '100')]);
        PaymentAllocation::create(['document_type' => 'payment', 'document_id' => 1, 'target_type' => 'purchase', 'target_id' => $p->id, 'amount' => '400', 'is_active' => true]);

        $this->assertSame('partial', $p->paymentStatus());
        $this->assertSame('600.00', $p->balanceDue());

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Payments have been applied');
        $this->svc->cancel($p, 'x');
    }
}
