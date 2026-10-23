<?php

namespace App\Services\Inventory;

use App\Exceptions\Inventory\InsufficientStockException;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\Setting;
use App\Support\Money;
use App\Support\Qty;
use Carbon\Carbon;
use DateTimeInterface;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The stock ledger. Every stock movement in the system goes through receive() or issue().
 *
 * Costing = MOVING weighted average, kept in exact integers (quantity in thousandths, value in paisa):
 *   receive:  value += cost;  qty += q;   average = value / qty
 *   issue:    cost  = value * q / qty;    value -= cost;  qty -= q   (issuing ALL stock removes exactly the remaining value,
 *             so rounding can never leave a residue behind)
 * Each ledger row stores the running balance, average and value after the movement, so any row can be audited alone.
 *
 * Chain order is ENTRY order (row id). txn_date is the business date used for reports; a back-dated entry still costs
 * against the running balance at the moment it is entered.
 *
 * Writers are serialised per product with a row lock, and rows are append-only: a correction is a reversing row.
 */
class InventoryService
{
    private const IN_TYPES = ['opening', 'purchase', 'sale_return', 'adjustment_in', 'production_output', 'reversal'];

    private const OUT_TYPES = ['purchase_return', 'sale', 'adjustment_out', 'production_consume', 'reversal'];

    /**
     * Stock in. Give either $unitCost (cost = qty x unit cost) or $totalCostCents (e.g. purchase line net of discount,
     * or the original cost when a sale return / reversal puts stock back).
     */
    public function receive(
        Product $product, string|int|float $qty, string $type, string|DateTimeInterface $date, ?Model $source = null,
        string|int|float|null $unitCost = null, ?int $totalCostCents = null, ?int $reversesId = null,
    ): InventoryTransaction {
        $this->assertType($type, self::IN_TYPES);
        $qtyMilli = $this->positiveQty($qty);

        if ($totalCostCents === null) {
            if ($unitCost === null) {
                throw new InvalidArgumentException('receive() needs a unit cost or a total cost.');
            }
            // qty(1e-3) x unit cost(1e-4) => 1e-7 rupees; divide by 1e5 to get paisa, rounding half-up
            $totalCostCents = intdiv($qtyMilli * Money::toScaled($unitCost, 4) + 50000, 100000);
        }
        if ($totalCostCents < 0) {
            throw new InvalidArgumentException('Cost cannot be negative.');
        }

        return DB::transaction(function () use ($product, $qtyMilli, $type, $date, $source, $unitCost, $totalCostCents, $reversesId) {
            $last = $this->lockAndGetLast($product);
            $this->assertChronological($product, $last, $date);
            $oldQty = $last ? Qty::toMilli($last->balance_qty) : 0;
            $oldValue = $last ? Money::toCents($last->balance_value) : 0;

            $newQty = $oldQty + $qtyMilli;
            $newValue = $oldValue + $totalCostCents;

            return $this->write($product, $type, $date, $source, $reversesId, [
                'qty_in' => Qty::fromMilli($qtyMilli), 'qty_out' => '0.000',
                'unit_cost' => $unitCost !== null ? Money::fromScaled(Money::toScaled($unitCost, 4), 4) : $this->unit4($totalCostCents, $qtyMilli),
                'total_cost' => Money::fromCents($totalCostCents),
                'balance_qty' => Qty::fromMilli($newQty), 'balance_value' => Money::fromCents($newValue),
                'avg_cost_after' => $newQty > 0 ? $this->unit4($newValue, $newQty) : ($last?->avg_cost_after ?? '0.0000'),
            ]);
        });
    }

    /**
     * Stock out at the current moving average (or at an explicit $totalCostCents when reversing / returning at original cost).
     * Refuses to go below zero unless Settings > allow_negative_stock is on.
     */
    public function issue(
        Product $product, string|int|float $qty, string $type, string|DateTimeInterface $date, ?Model $source = null,
        ?int $totalCostCents = null, ?int $reversesId = null,
    ): InventoryTransaction {
        $this->assertType($type, self::OUT_TYPES);
        $qtyMilli = $this->positiveQty($qty);

        return DB::transaction(function () use ($product, $qtyMilli, $type, $date, $source, $totalCostCents, $reversesId) {
            $last = $this->lockAndGetLast($product);
            $oldQty = $last ? Qty::toMilli($last->balance_qty) : 0;
            $oldValue = $last ? Money::toCents($last->balance_value) : 0;

            if ($qtyMilli > $oldQty && ! (bool) Setting::get('allow_negative_stock', false)) {
                throw new InsufficientStockException($product->name, Qty::fromMilli(max($oldQty, 0)), Qty::fromMilli($qtyMilli));
            }
            $this->assertChronological($product, $last, $date);

            $cost = $totalCostCents ?? $this->issueCost($product, $last, $oldQty, $oldValue, $qtyMilli);
            $newQty = $oldQty - $qtyMilli;
            $newValue = $oldValue - $cost;

            return $this->write($product, $type, $date, $source, $reversesId, [
                'qty_in' => '0.000', 'qty_out' => Qty::fromMilli($qtyMilli),
                'unit_cost' => $this->unit4($cost, $qtyMilli), 'total_cost' => Money::fromCents($cost),
                'balance_qty' => Qty::fromMilli($newQty), 'balance_value' => Money::fromCents($newValue),
                'avg_cost_after' => $newQty > 0 ? $this->unit4($newValue, $newQty) : ($last?->avg_cost_after ?? '0.0000'),
            ]);
        });
    }

    /**
     * Undo every not-yet-reversed movement of a source document (called when the document is cancelled).
     * Each movement is reversed at ITS OWN recorded cost, so inventory value mirrors the reversing journal entry exactly.
     * Cancelling a purchase whose stock has since been sold is refused (InsufficientStockException). Idempotent.
     *
     * @return Collection<int, InventoryTransaction>
     */
    public function reverseSource(Model $source, string|DateTimeInterface|null $date = null): Collection
    {
        return DB::transaction(function () use ($source, $date) {
            $rows = InventoryTransaction::where('source_type', $source->getMorphClass())
                ->where('source_id', $source->getKey())
                ->whereNull('reverses_id')
                ->whereNotIn('id', InventoryTransaction::whereNotNull('reverses_id')->select('reverses_id'))
                ->orderByDesc('id')->get();

            $reversals = collect();
            foreach ($rows as $row) {
                $product = Product::withTrashed()->findOrFail($row->product_id);
                $when = $date ?? $row->txn_date;
                $cost = Money::toCents($row->total_cost);

                $reversals->push(
                    (float) $row->qty_in > 0
                        ? $this->issue($product, $row->qty_in, 'reversal', $when, $source, $cost, $row->id)
                        : $this->receive($product, $row->qty_out, 'reversal', $when, $source, totalCostCents: $cost, reversesId: $row->id)
                );
            }

            return $reversals;
        });
    }

    // ------------------------------------------------------------------ internals

    private function lockAndGetLast(Product $product): ?InventoryTransaction
    {
        // Row lock on the product serialises concurrent movements (no-op on SQLite used in tests).
        Product::withTrashed()->whereKey($product->getKey())->lockForUpdate()->first();

        return InventoryTransaction::where('product_id', $product->getKey())->orderByDesc('id')->first();
    }

    private function assertChronological(Product $product, ?InventoryTransaction $last, string|DateTimeInterface $date): void
    {
        $movementDate = Carbon::parse($date)->toDateString();
        $lastMovementDate = $last?->txn_date?->toDateString();

        if ($lastMovementDate !== null && $movementDate < $lastMovementDate) {
            throw new DomainException(
                "Cannot add a {$movementDate} stock movement for {$product->name}: its latest ledger movement is dated {$lastMovementDate}. "
                .'Backdated stock changes are rejected to preserve moving-average valuation; use a chronological correction.'
            );
        }
    }

    private function issueCost(Product $product, ?InventoryTransaction $last, int $oldQty, int $oldValue, int $qty): int
    {
        if ($oldQty > 0 && $qty <= $oldQty) {
            return $qty === $oldQty ? $oldValue : (int) round($oldValue * ($qty / $oldQty));
        }

        // Negative stock is allowed: whatever the book value cannot cover is priced at the last known average cost.
        $avgRupees = (float) ($last?->avg_cost_after ?? $product->purchase_price);
        $covered = max($oldQty, 0);

        return ($covered > 0 ? $oldValue : 0) + (int) round((($qty - $covered) / 1000) * $avgRupees * 100);
    }

    private function write(Product $product, string $type, string|DateTimeInterface $date, ?Model $source, ?int $reversesId, array $figures): InventoryTransaction
    {
        $row = new InventoryTransaction($figures + [
            'product_id' => $product->getKey(),
            'txn_date' => Carbon::parse($date)->toDateString(),
            'txn_type' => $type,
            'source_type' => $source?->getMorphClass(),
            'source_id' => $source?->getKey(),
            'reverses_id' => $reversesId,
        ]);
        $row->forceFill(['created_by' => Auth::id()])->save();

        return $row;
    }

    private function positiveQty(string|int|float $qty): int
    {
        $milli = Qty::toMilli($qty);
        if ($milli <= 0) {
            throw new InvalidArgumentException('Quantity must be greater than zero.');
        }

        return $milli;
    }

    private function assertType(string $type, array $allowed): void
    {
        if (! in_array($type, $allowed, true)) {
            throw new InvalidArgumentException("Movement type '{$type}' is not valid here.");
        }
    }

    /** cost per unit with 4 decimals from paisa and thousandths (display / reporting value only) */
    private function unit4(int $cents, int $milli): string
    {
        return number_format(($cents / 100) / ($milli / 1000), 4, '.', '');
    }
}
