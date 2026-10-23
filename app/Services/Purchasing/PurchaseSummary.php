<?php

namespace App\Services\Purchasing;

use App\Models\PaymentAllocation;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * Payment position of purchases, derived (never stored) and computed for a whole page in two queries.
 *   paid     = paid at purchase time + payments allocated to it
 *   balance  = total - returns(posted) - paid + cash refunds received      (negative = overpaid)
 */
class PurchaseSummary
{
    /** @param Collection<int, Purchase> $purchases  @return array<int, array{paid:int, returned:int, refunded:int, balance:int, status:string}> */
    public static function for(Collection $purchases): array
    {
        $ids = $purchases->pluck('id')->filter()->all();

        $allocated = $ids ? PaymentAllocation::where('target_type', 'purchase')->where('is_active', true)->whereIn('target_id', $ids)
            ->selectRaw('target_id, SUM(amount) as a')->groupBy('target_id')->pluck('a', 'target_id') : collect();

        $returns = $ids ? PurchaseReturn::where('status', 'posted')->whereIn('purchase_id', $ids)
            ->selectRaw('purchase_id, SUM(total) as t, SUM(refund_now) as r')->groupBy('purchase_id')->get()->keyBy('purchase_id') : collect();

        $out = [];
        foreach ($purchases as $p) {
            $paid = Money::toCents($p->paid_now) + Money::toCents($allocated[$p->id] ?? 0);
            $returned = Money::toCents($returns[$p->id]->t ?? 0);
            $refunded = Money::toCents($returns[$p->id]->r ?? 0);
            $balance = Money::toCents($p->total) - $returned - $paid + $refunded;

            $status = match (true) {
                $p->status === 'cancelled' => 'cancelled',
                $p->status === 'draft'     => 'draft',
                $balance <= 0              => 'paid',
                ($paid - $refunded) > 0    => 'partial',
                default                    => 'unpaid',
            };

            $out[$p->id] = compact('paid', 'returned', 'refunded', 'balance', 'status');
        }

        return $out;
    }
}
