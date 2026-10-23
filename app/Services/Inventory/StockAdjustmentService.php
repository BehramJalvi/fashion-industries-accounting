<?php

namespace App\Services\Inventory;

use App\Models\Product;
use App\Models\StockAdjustment;
use App\Models\NumberSequence;
use App\Services\Accounting\PostingService;
use App\Support\AccountCodes;
use App\Support\Money;
use DomainException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Stock adjustment lifecycle: draft -> posted -> cancelled.
 *  IN  items go in at the unit cost you enter:   Dr Inventory / Cr Inventory Gain-Loss
 *  OUT items go out at the moving average cost:  Dr Inventory Gain-Loss / Cr Inventory
 * Posting and cancelling are atomic: stock ledger, journal and document status succeed or fail together.
 */
class StockAdjustmentService
{
    public function __construct(private InventoryService $inventory, private PostingService $posting)
    {
    }

    /** @param array{adjustment_date: string, reason: string, notes?: ?string} $data
     *  @param array<int, array{product_id: int, direction: string, qty: string|float|int, unit_cost?: string|float|int|null}> $items */
    public function createDraft(array $data, array $items): StockAdjustment
    {
        return DB::transaction(function () use ($data, $items) {
            $adjustment = new StockAdjustment($data + ['adjustment_no' => NumberSequence::next('stock_adjustment')]);
            $adjustment->forceFill(['status' => 'draft', 'created_by' => Auth::id()])->save();

            foreach ($items as $item) {
                $adjustment->items()->create([
                    'product_id' => $item['product_id'], 'direction' => $item['direction'],
                    'qty' => $item['qty'], 'unit_cost' => $item['direction'] === 'in' ? ($item['unit_cost'] ?? null) : null,
                ]);
            }

            return $adjustment->load('items.product');
        });
    }

    public function post(StockAdjustment $adjustment): StockAdjustment
    {
        return DB::transaction(function () use ($adjustment) {
            $adj = StockAdjustment::with('items')->lockForUpdate()->findOrFail($adjustment->id);
            if (! $adj->isDraft()) {
                throw new DomainException('Only a draft stock adjustment can be posted.');
            }
            if ($adj->items->isEmpty()) {
                throw new DomainException('The adjustment has no items.');
            }

            $inCents = $outCents = 0;
            foreach ($adj->items as $item) {
                $product = Product::findOrFail($item->product_id);

                if ($item->direction === 'in') {
                    if ($item->unit_cost === null) {
                        throw new DomainException("A unit cost is required for stock coming in ({$product->name}).");
                    }
                    $row = $this->inventory->receive($product, $item->qty, 'adjustment_in', $adj->adjustment_date, $adj, unitCost: $item->unit_cost);
                    $inCents += Money::toCents($row->total_cost);
                } else {
                    $row = $this->inventory->issue($product, $item->qty, 'adjustment_out', $adj->adjustment_date, $adj);
                    $outCents += Money::toCents($row->total_cost);
                    $item->update(['unit_cost' => $row->unit_cost]);   // record the cost actually used
                }
            }

            $lines = [];
            if ($inCents > 0) {
                $lines[] = ['account' => AccountCodes::INVENTORY, 'debit' => Money::fromCents($inCents)];
                $lines[] = ['account' => AccountCodes::INVENTORY_ADJUSTMENT, 'credit' => Money::fromCents($inCents)];
            }
            if ($outCents > 0) {
                $lines[] = ['account' => AccountCodes::INVENTORY_ADJUSTMENT, 'debit' => Money::fromCents($outCents)];
                $lines[] = ['account' => AccountCodes::INVENTORY, 'credit' => Money::fromCents($outCents)];
            }

            $entry = $lines ? $this->posting->post([
                'date' => $adj->adjustment_date, 'voucher_type' => 'adjustment', 'reference' => $adj->adjustment_no,
                'description' => "Stock adjustment {$adj->adjustment_no}: {$adj->reason}", 'source' => $adj, 'lines' => $lines,
            ]) : null;

            $adj->forceFill([
                'status' => 'posted', 'journal_entry_id' => $entry?->id, 'posted_at' => now(), 'updated_by' => Auth::id(),
            ])->save();

            return $adj;
        });
    }

    public function cancel(StockAdjustment $adjustment, string $reason): StockAdjustment
    {
        return DB::transaction(function () use ($adjustment, $reason) {
            $adj = StockAdjustment::lockForUpdate()->findOrFail($adjustment->id);
            if (! $adj->isPosted()) {
                throw new DomainException('Only a posted stock adjustment can be cancelled.');
            }

            $this->inventory->reverseSource($adj);          // throws if the added stock has already been used
            $reversal = $adj->journalEntry ? $this->posting->reverse($adj->journalEntry, $reason) : null;

            $adj->forceFill([
                'status' => 'cancelled', 'reversal_entry_id' => $reversal?->id, 'cancelled_at' => now(),
                'cancelled_by' => Auth::id(), 'cancel_reason' => $reason, 'updated_by' => Auth::id(),
            ])->save();

            return $adj;
        });
    }
}
