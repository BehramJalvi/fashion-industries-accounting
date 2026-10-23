<?php

namespace App\Services\Inventory;

use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Services\Accounting\PostingService;
use App\Support\AccountCodes;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Opening stock = stock-ledger row + journal (Dr Inventory / Cr Opening Balance Equity), atomically. */
class OpeningStockService
{
    public function __construct(private InventoryService $inventory, private PostingService $posting)
    {
    }

    public function record(Product $product, string|int|float $qty, string|int|float $unitCost, string $date): InventoryTransaction
    {
        return DB::transaction(function () use ($product, $qty, $unitCost, $date) {
            if (InventoryTransaction::where('product_id', $product->id)->where('txn_type', 'opening')->exists()) {
                throw new DomainException("Opening stock for {$product->name} has already been recorded. Use a stock adjustment to change it.");
            }

            $row = $this->inventory->receive($product, $qty, 'opening', $date, $product, unitCost: $unitCost);

            $this->posting->post([
                'date' => $date, 'voucher_type' => 'opening', 'source' => $product,
                'description' => "Opening stock - {$product->name}",
                'lines' => [
                    ['account' => AccountCodes::INVENTORY, 'debit' => $row->total_cost, 'product_id' => $product->id],
                    ['account' => AccountCodes::OPENING_EQUITY, 'credit' => $row->total_cost],
                ],
            ]);

            return $row;
        });
    }
}
