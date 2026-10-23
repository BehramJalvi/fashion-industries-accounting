<?php

namespace App\Services\Production;

use App\Models\Account;
use App\Models\NumberSequence;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProductionOutput;
use App\Models\Supplier;
use App\Services\Accounting\PostingService;
use App\Services\Inventory\InventoryService;
use App\Support\AccountCodes;
use App\Support\Money;
use Carbon\Carbon;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ProductionOrderService
{
    public function __construct(private InventoryService $inventory, private PostingService $posting) {}

    /**
     * @param  array<string, mixed>  $header
     * @param  array<int, array{product_id:int, qty:string|float|int}>  $inputs
     */
    public function createDraft(array $header, array $inputs): ProductionOrder
    {
        $outputs = $this->requestedOutputs($header);
        $this->assertProducts($inputs, $outputs);

        $fee = Money::toCents($header['processing_fee'] ?? 0);
        $paidFrom = $header['paid_from_account_id'] ?? null;
        $processorId = $header['processor_supplier_id'] ?? null;
        $this->assertFeeSettlement($fee, $paidFrom, $processorId);

        return DB::transaction(function () use ($header, $inputs, $outputs, $fee, $paidFrom, $processorId) {
            $order = new ProductionOrder([
                'order_no' => NumberSequence::next('production_order'),
                'order_date' => Carbon::parse($header['order_date'])->toDateString(),
                'processor_supplier_id' => $processorId,
                'processing_fee' => Money::fromCents($fee),
                'paid_from_account_id' => $paidFrom,
                'notes' => $header['notes'] ?? null,
            ]);
            $order->forceFill(['status' => 'draft', 'created_by' => Auth::id()])->save();
            $order->inputs()->createMany(array_map(fn (array $input) => [
                'product_id' => (int) $input['product_id'],
                'qty' => $input['qty'],
            ], $inputs));
            $order->outputs()->createMany($outputs);

            return $order->load(['inputs.product.unit', 'outputs.product.unit', 'processor', 'paidFromAccount']);
        });
    }

    public function post(ProductionOrder $productionOrder): ProductionOrder
    {
        return DB::transaction(function () use ($productionOrder) {
            $order = ProductionOrder::with(['inputs', 'outputs'])->lockForUpdate()->findOrFail($productionOrder->id);
            if (! $order->isDraft()) {
                throw new DomainException('Only a draft production transaction can be posted.');
            }
            if ($order->inputs->isEmpty() || $order->outputs->isEmpty()) {
                throw new DomainException('A production transaction must have raw material inputs and at least one finished product.');
            }

            $inputCostCents = 0;
            $lines = [];
            foreach ($order->inputs as $input) {
                $product = Product::findOrFail($input->product_id);
                if (! $product->is_active) {
                    throw new DomainException("{$product->name} is inactive and cannot be used in production.");
                }

                $movement = $this->inventory->issue($product, $input->qty, 'production_consume', $order->order_date, $order);
                $costCents = Money::toCents($movement->total_cost);
                $inputCostCents += $costCents;
                $input->update(['unit_cost' => $movement->unit_cost]);

                if ($costCents > 0) {
                    $lines[] = [
                        'account' => AccountCodes::INVENTORY,
                        'credit' => Money::fromCents($costCents),
                        'product_id' => $product->id,
                        'narration' => 'Raw material consumed in production',
                    ];
                }
            }

            $feeCents = Money::toCents($order->processing_fee);
            $this->assertFeeSettlement($feeCents, $order->paid_from_account_id, $order->processor_supplier_id);

            $outputCostCents = $inputCostCents + $feeCents;
            $allocations = $this->allocateCost($order->outputs, $outputCostCents);
            foreach ($order->outputs as $index => $output) {
                $outputProduct = Product::findOrFail($output->product_id);
                if (! $outputProduct->is_active) {
                    throw new DomainException("{$outputProduct->name} is inactive and cannot be used in production.");
                }

                $allocatedCost = $allocations[$index];
                $outputMovement = $this->inventory->receive(
                    $outputProduct,
                    $output->qty,
                    'production_output',
                    $order->order_date,
                    $order,
                    totalCostCents: $allocatedCost
                );
                $output->update(['unit_cost' => $outputMovement->unit_cost]);

                if ($allocatedCost > 0) {
                    $lines[] = [
                        'account' => AccountCodes::INVENTORY,
                        'debit' => Money::fromCents($allocatedCost),
                        'product_id' => $outputProduct->id,
                        'narration' => 'Finished product received from production',
                    ];
                }
            }

            if ($feeCents > 0) {
                if ($order->paid_from_account_id) {
                    $lines[] = [
                        'account' => (int) $order->paid_from_account_id,
                        'credit' => Money::fromCents($feeCents),
                        'narration' => 'Production processing fee paid',
                    ];
                } else {
                    $processor = Supplier::findOrFail($order->processor_supplier_id);
                    $lines[] = [
                        'account' => AccountCodes::PAYABLE,
                        'credit' => Money::fromCents($feeCents),
                        'party' => $processor,
                        'narration' => 'Production processing fee owed',
                    ];
                }
            }

            $entry = $lines ? $this->posting->post([
                'date' => $order->order_date,
                'voucher_type' => 'production',
                'reference' => $order->order_no,
                'source' => $order,
                'description' => "Production {$order->order_no}: raw materials converted to finished goods",
                'lines' => $lines,
            ]) : null;

            $order->forceFill([
                'status' => 'posted',
                'journal_entry_id' => $entry?->id,
                'posted_at' => now(),
                'updated_by' => Auth::id(),
            ])->save();

            return $order;
        });
    }

    public function cancel(ProductionOrder $productionOrder, string $reason): ProductionOrder
    {
        return DB::transaction(function () use ($productionOrder, $reason) {
            $order = ProductionOrder::lockForUpdate()->findOrFail($productionOrder->id);
            if (! $order->isPosted()) {
                throw new DomainException('Only a posted production transaction can be cancelled.');
            }

            $this->inventory->reverseSource($order);
            $reversal = $order->journalEntry ? $this->posting->reverse($order->journalEntry, $reason) : null;

            $order->forceFill([
                'status' => 'cancelled',
                'reversal_entry_id' => $reversal?->id,
                'cancelled_at' => now(),
                'cancelled_by' => Auth::id(),
                'cancel_reason' => $reason,
                'updated_by' => Auth::id(),
            ])->save();

            return $order;
        });
    }

    /**
     * @param  array<int, array{product_id:int, qty:string|float|int}>  $inputs
     */
    private function assertProducts(array $inputs, array $outputs): void
    {
        if (! $inputs || ! $outputs) {
            throw new DomainException('Add at least one raw material input and one finished product.');
        }

        $inputProductIds = collect($inputs)->pluck('product_id')->map(fn ($id) => (int) $id)->unique();
        $outputProductIds = collect($outputs)->pluck('product_id')->map(fn ($id) => (int) $id);
        if ($outputProductIds->count() !== $outputProductIds->unique()->count()) {
            throw new DomainException('Each finished product can only appear once in a production order.');
        }
        if ($outputProductIds->contains(fn (int $id) => $inputProductIds->contains($id))) {
            throw new DomainException('Finished products must be different from raw materials.');
        }

        $allProductIds = $inputProductIds->concat($outputProductIds)->unique();
        $products = Product::whereIn('id', $allProductIds)->get();
        if ($products->count() !== $allProductIds->count() || $products->contains(fn (Product $product) => ! $product->is_active)) {
            throw new DomainException('Select active products for the raw materials and finished products.');
        }
    }

    /**
     * @param  array<string, mixed>  $header
     * @return array<int, array{product_id:int, qty:string|float|int, allocation_basis:?string}>
     */
    private function requestedOutputs(array $header): array
    {
        $outputs = $header['outputs'] ?? [[
            'product_id' => $header['output_product_id'] ?? null,
            'qty' => $header['output_qty'] ?? null,
        ]];

        return array_values(array_map(fn (array $output): array => [
            'product_id' => (int) $output['product_id'],
            'qty' => $output['qty'],
            'allocation_basis' => isset($output['allocation_basis'])
                ? Money::fromCents(Money::toCents($output['allocation_basis']))
                : (count($outputs) === 1 ? '1.00' : null),
        ], $outputs));
    }

    /**
     * @param  Collection<int, ProductionOutput>  $outputs
     * @return array<int, int> Allocated cents in output-row order.
     */
    private function allocateCost(Collection $outputs, int $totalCostCents): array
    {
        if ($outputs->count() === 1) {
            return [$totalCostCents];
        }

        $basisByIndex = [];
        $totalBasis = 0;
        foreach ($outputs as $index => $output) {
            $basis = Money::toCents($output->allocation_basis);
            if ($basis <= 0) {
                throw new DomainException('Every finished product needs a positive estimated production cost for cost allocation.');
            }
            $basisByIndex[$index] = $basis;
            $totalBasis += $basis;
        }

        $allocations = [];
        $remainders = [];
        $allocatedCents = 0;
        foreach ($basisByIndex as $index => $basis) {
            [$allocation, $remainder] = $this->multiplyDivide($totalCostCents, $basis, $totalBasis);
            $allocations[$index] = $allocation;
            $remainders[$index] = $remainder;
            $allocatedCents += $allocation;
        }

        $remainderCents = $totalCostCents - $allocatedCents;
        $indicesByRemainder = array_keys($remainders);
        usort($indicesByRemainder, fn (int $left, int $right): int => $remainders[$right] <=> $remainders[$left] ?: $left <=> $right);
        for ($index = 0; $index < $remainderCents; $index++) {
            $allocations[$indicesByRemainder[$index]]++;
        }
        ksort($allocations);

        return array_values($allocations);
    }

    /**
     * Divide a product without overflowing the platform integer or introducing floating-point money math.
     *
     * @return array{int, int} Quotient and remainder of $left * $right / $divisor.
     */
    private function multiplyDivide(int $left, int $right, int $divisor): array
    {
        $bits = [];
        while ($right > 0) {
            array_unshift($bits, $right % 2);
            $right = intdiv($right, 2);
        }

        $quotient = 0;
        $remainder = 0;
        foreach ($bits as $bit) {
            $partial = $remainder * 2 + $bit * $left;
            $quotient = $quotient * 2 + intdiv($partial, $divisor);
            $remainder = $partial % $divisor;
        }

        return [$quotient, $remainder];
    }

    private function assertFeeSettlement(int $feeCents, mixed $paidFromAccountId, mixed $processorSupplierId): void
    {
        if ($processorSupplierId && ! Supplier::whereKey($processorSupplierId)->exists()) {
            throw new DomainException('Choose an existing processor supplier.');
        }
        if ($feeCents > 0 && ! $paidFromAccountId && ! $processorSupplierId) {
            throw new DomainException('Choose the processor to owe, or the cash/bank account used to pay the processing fee.');
        }
        if ($feeCents <= 0 && $paidFromAccountId) {
            throw new DomainException('A payment account can only be selected when a processing fee is entered.');
        }

        if ($paidFromAccountId) {
            $account = Account::find($paidFromAccountId);
            if (! $account || ! $account->is_active || ! in_array($account->sub_type, ['cash', 'bank'], true)) {
                throw new DomainException('Choose an active cash or bank account for the processing fee.');
            }
        }

    }
}
