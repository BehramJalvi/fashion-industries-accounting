<?php

namespace Tests\Feature;

use App\Exceptions\Inventory\InsufficientStockException;
use App\Models\InventoryTransaction;
use App\Models\JournalEntryLine;
use App\Models\Product;
use App\Models\Unit;
use App\Services\Inventory\InventoryService;
use App\Services\Inventory\OpeningStockService;
use App\Services\Inventory\StockAdjustmentService;
use App\Support\AccountCodes;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\FiscalYearSeeder;
use Database\Seeders\NumberSequenceSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockAdjustmentAndOpeningTest extends TestCase
{
    use RefreshDatabase;

    private Product $thread;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([FiscalYearSeeder::class, ChartOfAccountsSeeder::class, NumberSequenceSeeder::class]);
        $unit = Unit::create(['name' => 'Bundle']);
        $this->thread = Product::create(['code' => 'P001', 'name' => 'Grey Thread', 'unit_id' => $unit->id, 'product_type' => 'finished_good', 'purchase_price' => 0, 'selling_price' => 100]);
    }

    private function glInventory(): string
    {
        $r = JournalEntryLine::join('accounts as a', 'a.id', '=', 'journal_entry_lines.account_id')->where('a.code', AccountCodes::INVENTORY)
            ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) as bal')->value('bal');

        return number_format((float) $r, 2, '.', '');
    }

    private function ledgerValue(): string
    {
        return number_format((float) InventoryTransaction::where('product_id', $this->thread->id)->orderByDesc('id')->value('balance_value'), 2, '.', '');
    }

    private function assertJournalBalances(): void
    {
        $this->assertSame((string) JournalEntryLine::sum('debit'), (string) JournalEntryLine::sum('credit'));
    }

    public function test_opening_stock_posts_inventory_against_opening_equity_and_only_once(): void
    {
        $opening = app(OpeningStockService::class);
        $opening->record($this->thread, 204, 150, '2026-07-01');       // 204 x 150 = 30,600 (the workbook's opening stock)

        $this->assertSame('30600.00', $this->glInventory());
        $this->assertSame('30600.00', $this->ledgerValue());
        $this->assertSame('30600.00', number_format((float) JournalEntryLine::whereHas('account', fn ($q) => $q->where('code', AccountCodes::OPENING_EQUITY))->sum('credit'), 2, '.', ''));
        $this->assertJournalBalances();

        $this->expectException(DomainException::class);
        $opening->record($this->thread, 1, 1, '2026-07-02');
    }

    public function test_adjustment_in_and_out_keep_stock_ledger_and_inventory_account_in_step(): void
    {
        app(OpeningStockService::class)->record($this->thread, 100, 100, '2026-10-01');
        $service = app(StockAdjustmentService::class);

        $adj = $service->createDraft(['adjustment_date' => '2026-10-05', 'reason' => 'Count'], [
            ['product_id' => $this->thread->id, 'direction' => 'out', 'qty' => 10],
            ['product_id' => $this->thread->id, 'direction' => 'in', 'qty' => 4, 'unit_cost' => 120],
        ]);
        $this->assertSame('draft', $adj->status);
        $this->assertSame('100.000', $this->thread->currentStock());   // drafts move no stock

        $service->post($adj);

        $adj->refresh();
        $this->assertSame('posted', $adj->status);
        $this->assertNotNull($adj->journal_entry_id);
        $this->assertSame('94.000', $this->thread->currentStock());           // 100 - 10 + 4
        $this->assertSame('9480.00', $this->ledgerValue());                   // 10,000 - 1,000 (out @100) + 480 (in 4 x 120)
        $this->assertSame($this->ledgerValue(), $this->glInventory());        // control check: ledger = GL
        $this->assertJournalBalances();
    }

    public function test_cancelling_a_posted_adjustment_restores_everything(): void
    {
        app(OpeningStockService::class)->record($this->thread, 100, 100, '2026-10-01');
        $service = app(StockAdjustmentService::class);
        $adj = $service->post($service->createDraft(['adjustment_date' => '2026-10-05', 'reason' => 'Damaged'], [
            ['product_id' => $this->thread->id, 'direction' => 'out', 'qty' => 25],
        ]));
        $this->assertSame('7500.00', $this->glInventory());

        $service->cancel($adj, 'entered by mistake');

        $adj->refresh();
        $this->assertSame('cancelled', $adj->status);
        $this->assertNotNull($adj->reversal_entry_id);
        $this->assertSame('100.000', $this->thread->currentStock());
        $this->assertSame('10000.00', $this->ledgerValue());
        $this->assertSame('10000.00', $this->glInventory());
        $this->assertJournalBalances();
        $this->assertSame(1, InventoryTransaction::where('txn_type', 'reversal')->count());   // original row kept, reversal added
    }

    public function test_posted_adjustment_cannot_be_edited_or_deleted_and_cancelled_cannot_be_reposted(): void
    {
        app(OpeningStockService::class)->record($this->thread, 10, 10, '2026-10-01');
        $service = app(StockAdjustmentService::class);
        $adj = $service->post($service->createDraft(['adjustment_date' => '2026-10-05', 'reason' => 'x'], [['product_id' => $this->thread->id, 'direction' => 'out', 'qty' => 1]]));

        foreach ([fn () => $adj->update(['reason' => 'edited']), fn () => $adj->delete()] as $attempt) {
            try {
                $attempt();
                $this->fail('Expected DomainException');
            } catch (DomainException) {
                $this->addToAssertionCount(1);
            }
        }

        $service->cancel($adj, 'test');
        $this->expectException(DomainException::class);
        $service->post($adj);
    }

    public function test_adjustment_out_beyond_stock_is_refused_and_leaves_no_trace(): void
    {
        app(OpeningStockService::class)->record($this->thread, 5, 10, '2026-10-01');
        $service = app(StockAdjustmentService::class);
        $adj = $service->createDraft(['adjustment_date' => '2026-10-05', 'reason' => 'x'], [['product_id' => $this->thread->id, 'direction' => 'out', 'qty' => 6]]);

        try {
            $service->post($adj);
            $this->fail('Expected InsufficientStockException');
        } catch (InsufficientStockException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame('draft', $adj->fresh()->status);
        $this->assertSame('5.000', $this->thread->currentStock());
        $this->assertSame('50.00', $this->glInventory());
    }

    public function test_cannot_cancel_an_adjustment_whose_added_stock_was_used(): void
    {
        $service = app(StockAdjustmentService::class);
        $adj = $service->post($service->createDraft(['adjustment_date' => '2026-10-05', 'reason' => 'Found stock'], [
            ['product_id' => $this->thread->id, 'direction' => 'in', 'qty' => 10, 'unit_cost' => 50],
        ]));
        app(InventoryService::class)->issue($this->thread, 8, 'sale', '2026-10-06');

        $this->expectException(InsufficientStockException::class);
        $service->cancel($adj, 'oops');
    }

    public function test_stock_in_requires_a_unit_cost(): void
    {
        $service = app(StockAdjustmentService::class);
        $adj = $service->createDraft(['adjustment_date' => '2026-10-05', 'reason' => 'x'], [['product_id' => $this->thread->id, 'direction' => 'in', 'qty' => 1]]);

        $this->expectException(DomainException::class);
        $service->post($adj);
    }
}
