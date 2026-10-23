<?php

namespace Tests\Feature;

use App\Exceptions\Inventory\InsufficientStockException;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Unit;
use App\Services\Inventory\InventoryService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryServiceTest extends TestCase
{
    use RefreshDatabase;

    private InventoryService $inventory;

    private Product $yarn;

    protected function setUp(): void
    {
        parent::setUp();
        $this->inventory = app(InventoryService::class);
        $unit = Unit::create(['name' => 'Cone']);
        $this->yarn = Product::create(['code' => 'Y1', 'name' => 'Yarn', 'unit_id' => $unit->id, 'product_type' => 'raw_material', 'purchase_price' => 100, 'selling_price' => 0]);
    }

    private function last(): InventoryTransaction
    {
        return InventoryTransaction::where('product_id', $this->yarn->id)->orderByDesc('id')->firstOrFail();
    }

    public function test_weighted_average_example_from_the_brief(): void
    {
        // 100 x 100 = 10,000 ; then 50 x 120 = 6,000  =>  16,000 / 150 = 106.67
        $this->inventory->receive($this->yarn, 100, 'purchase', '2026-10-01', unitCost: 100);
        $row = $this->inventory->receive($this->yarn, 50, 'purchase', '2026-10-02', unitCost: 120);

        $this->assertSame('150.000', $row->balance_qty);
        $this->assertSame('16000.00', $row->balance_value);
        $this->assertSame('106.6667', $row->avg_cost_after);
        $this->assertSame('150.000', $this->yarn->currentStock());
        $this->assertSame('106.6667', $this->yarn->averageCost());
        $this->assertSame('16000.00', $this->yarn->stockValue());
    }

    public function test_issue_is_costed_at_the_moving_average_and_average_is_unchanged(): void
    {
        $this->inventory->receive($this->yarn, 100, 'purchase', '2026-10-01', unitCost: 100);
        $this->inventory->receive($this->yarn, 50, 'purchase', '2026-10-02', unitCost: 120);

        $sale = $this->inventory->issue($this->yarn, 30, 'sale', '2026-10-03');

        $this->assertSame('3200.00', $sale->total_cost);          // 30 x 106.6667
        $this->assertSame('120.000', $sale->balance_qty);
        $this->assertSame('12800.00', $sale->balance_value);
        $this->assertSame('106.6667', $sale->avg_cost_after);
    }

    public function test_issuing_everything_leaves_no_rounding_residue(): void
    {
        $this->inventory->receive($this->yarn, 3, 'purchase', '2026-10-01', totalCostCents: 1000);   // 10.00 for 3 units

        $costs = [];
        foreach ([1, 1, 1] as $q) {
            $costs[] = $this->inventory->issue($this->yarn, $q, 'sale', '2026-10-02')->total_cost;
        }

        $this->assertSame(['3.33', '3.34', '3.33'], $costs);
        $this->assertSame('10.00', number_format(array_sum($costs), 2, '.', ''));   // exactly what was bought
        $this->assertSame('0.000', $this->last()->balance_qty);
        $this->assertSame('0.00', $this->last()->balance_value);
    }

    public function test_cannot_sell_more_than_available_unless_negative_stock_is_enabled(): void
    {
        $this->inventory->receive($this->yarn, 10, 'purchase', '2026-10-01', unitCost: 50);

        try {
            $this->inventory->issue($this->yarn, 11, 'sale', '2026-10-02');
            $this->fail('Expected InsufficientStockException');
        } catch (InsufficientStockException $e) {
            $this->assertStringContainsString('10.000 available', $e->getMessage());
        }
        $this->assertSame('10.000', $this->yarn->currentStock());   // nothing was written

        Setting::set('allow_negative_stock', true);
        $row = $this->inventory->issue($this->yarn, 11, 'sale', '2026-10-02');
        $this->assertSame('-1.000', $row->balance_qty);
        $this->assertSame('550.00', $row->total_cost);              // 10 at book value 500 + 1 at last average 50
    }

    public function test_quantity_must_be_positive(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->inventory->receive($this->yarn, 0, 'purchase', '2026-10-01', unitCost: 10);
    }

    public function test_reversal_restores_stock_at_original_cost_and_is_idempotent(): void
    {
        $this->inventory->receive($this->yarn, 100, 'purchase', '2026-10-01', unitCost: 100);
        $source = Product::create(['code' => 'DOC', 'name' => 'stand-in source document', 'unit_id' => $this->yarn->unit_id, 'product_type' => 'trading', 'purchase_price' => 0, 'selling_price' => 0]);

        $this->inventory->issue($this->yarn, 40, 'sale', '2026-10-02', $source);
        $this->assertSame('6000.00', $this->last()->balance_value);

        $rev = $this->inventory->reverseSource($source);

        $this->assertCount(1, $rev);
        $this->assertSame('reversal', $rev[0]->txn_type);
        $this->assertSame('100.000', $this->last()->balance_qty);
        $this->assertSame('10000.00', $this->last()->balance_value);
        $this->assertSame('100.0000', $this->last()->avg_cost_after);
        $this->assertCount(0, $this->inventory->reverseSource($source));   // second call does nothing
    }

    public function test_backdated_receipts_are_rejected_without_changing_the_inventory_chain(): void
    {
        $this->inventory->receive($this->yarn, 10, 'purchase', '2026-10-01', unitCost: 100);
        $last = $this->inventory->receive($this->yarn, 10, 'purchase', '2026-10-03', unitCost: 200);

        try {
            $this->inventory->receive($this->yarn, 5, 'purchase', '2026-10-02', unitCost: 300);
            $this->fail('Expected a backdated stock movement to be rejected.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('Backdated stock changes are rejected', $exception->getMessage());
        }

        $this->assertSame(2, InventoryTransaction::where('product_id', $this->yarn->id)->count());
        $this->assertSame('20.000', $last->fresh()->balance_qty);
        $this->assertSame('3000.00', $last->fresh()->balance_value);
        $this->assertSame('20.000', $this->yarn->currentStock());
    }

    public function test_backdated_issues_are_rejected_without_creating_a_costed_issue(): void
    {
        $this->inventory->receive($this->yarn, 10, 'purchase', '2026-10-01', unitCost: 100);
        $this->inventory->receive($this->yarn, 10, 'purchase', '2026-10-03', unitCost: 200);

        try {
            $this->inventory->issue($this->yarn, 1, 'sale', '2026-10-02');
            $this->fail('Expected a backdated stock movement to be rejected.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('Backdated stock changes are rejected', $exception->getMessage());
        }

        $this->assertSame(2, InventoryTransaction::where('product_id', $this->yarn->id)->count());
        $this->assertSame('20.000', $this->yarn->currentStock());
        $this->assertSame('3000.00', $this->yarn->stockValue());
    }

    public function test_cancelling_a_receipt_of_stock_that_was_already_used_is_refused(): void
    {
        $source = Product::create(['code' => 'DOC', 'name' => 'purchase stand-in', 'unit_id' => $this->yarn->unit_id, 'product_type' => 'trading', 'purchase_price' => 0, 'selling_price' => 0]);
        $this->inventory->receive($this->yarn, 10, 'purchase', '2026-10-01', $source, unitCost: 100);
        $this->inventory->issue($this->yarn, 8, 'sale', '2026-10-02');

        $this->expectException(InsufficientStockException::class);
        $this->inventory->reverseSource($source);
    }

    public function test_ledger_rows_are_append_only(): void
    {
        $row = $this->inventory->receive($this->yarn, 5, 'purchase', '2026-10-01', unitCost: 10);

        $this->expectException(DomainException::class);
        $row->update(['qty_in' => 99]);
    }
}
