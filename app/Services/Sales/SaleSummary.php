<?php

namespace App\Services\Sales;

use App\Models\PaymentAllocation;
use App\Models\Sale;
use App\Models\SalesReturn;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * Payment position of sales, derived (never stored) and computed for a whole page in two queries.
 *   paid     = received at the time of sale + receipts allocated to it
 *   balance  = total - returns(posted) - paid + cash refunded to the customer      (negative = customer overpaid)
 */
class SaleSummary
{
    /** @param Collection<int, Sale> $sales  @return array<int, array{paid:int, returned:int, refunded:int, balance:int, status:string}> */
    public static function for(Collection $sales): array
    {
        $ids = $sales->pluck('id')->filter()->all();

        $allocated = $ids ? PaymentAllocation::where('target_type', 'sale')->where('is_active', true)->whereIn('target_id', $ids)
            ->selectRaw('target_id, SUM(amount) as a')->groupBy('target_id')->pluck('a', 'target_id') : collect();

        $returns = $ids ? SalesReturn::where('status', 'posted')->whereIn('sale_id', $ids)
            ->selectRaw('sale_id, SUM(total) as t, SUM(refund_now) as r')->groupBy('sale_id')->get()->keyBy('sale_id') : collect();

        $out = [];
        foreach ($sales as $s) {
            $paid = Money::toCents($s->received_now) + Money::toCents($allocated[$s->id] ?? 0);
            $returned = Money::toCents($returns[$s->id]->t ?? 0);
            $refunded = Money::toCents($returns[$s->id]->r ?? 0);
            $balance = Money::toCents($s->total) - $returned - $paid + $refunded;

            $status = match (true) {
                $s->status === 'cancelled' => 'cancelled',
                $s->status === 'draft'     => 'draft',
                $balance <= 0              => 'paid',
                ($paid - $refunded) > 0    => 'partial',
                default                    => 'unpaid',
            };

            $out[$s->id] = compact('paid', 'returned', 'refunded', 'balance', 'status');
        }

        return $out;
    }
}
