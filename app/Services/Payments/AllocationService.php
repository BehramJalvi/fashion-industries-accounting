<?php

namespace App\Services\Payments;

use App\Models\Customer;
use App\Models\Purchase;
use App\Models\Sale;
use App\Services\Purchasing\PurchaseSummary;
use App\Services\Sales\SaleSummary;
use App\Support\Money;
use DomainException;
use Illuminate\Support\Collection;

/**
 * Which invoices / bills are still open for a party, and how a receipt or payment may be split across them.
 * This replaces the workbook's "retype the invoice number as the reference" matching.
 */
class AllocationService
{
    /** Posted invoices of a customer that still have a balance, oldest first. @return Collection<int, array{model:Sale, balance:int, label:string}> */
    public function openSales(Customer|int $customer): Collection
    {
        $id = $customer instanceof Customer ? $customer->id : $customer;
        $sales = Sale::where('customer_id', $id)->where('status', 'posted')->orderBy('sale_date')->orderBy('id')->get();
        $summary = SaleSummary::for($sales);

        return $sales->map(fn (Sale $s) => ['model' => $s, 'balance' => $summary[$s->id]['balance'], 'label' => "Invoice {$s->invoice_no}"])
            ->filter(fn ($r) => $r['balance'] > 0)->values();
    }

    /**
     * Posted purchases still owed to a supplier or to an employee who paid for them, oldest first.
     * A purchase an employee paid for is owed to the EMPLOYEE (even if a supplier is recorded on it).
     *
     * @return Collection<int, array{model:Purchase, balance:int, label:string}>
     */
    public function openPurchases(string $payeeType, int $payeeId): Collection
    {
        $query = Purchase::where('status', 'posted');
        $payeeType === 'employee'
            ? $query->where('funded_by_employee_id', $payeeId)
            : $query->where('supplier_id', $payeeId)->whereNull('funded_by_employee_id');

        $purchases = $query->orderBy('purchase_date')->orderBy('id')->get();
        $summary = PurchaseSummary::for($purchases);

        return $purchases->map(fn (Purchase $p) => ['model' => $p, 'balance' => $summary[$p->id]['balance'], 'label' => "{$p->purchase_no}" . ($p->supplier_ref ? " ({$p->supplier_ref})" : '')])
            ->filter(fn ($r) => $r['balance'] > 0)->values();
    }

    /** Apply an amount to the oldest open items first. @return array<int,int> document id => paisa */
    public function auto(Collection $open, int $amountCents): array
    {
        $out = [];
        $left = $amountCents;
        foreach ($open as $row) {
            if ($left <= 0) {
                break;
            }
            $take = min($left, $row['balance']);
            $out[$row['model']->id] = $take;
            $left -= $take;
        }

        return $out;
    }

    /**
     * Check a requested split (document id => amount) against what each item still owes and against the total available.
     *
     * @param array<int|string, string|float|int|null> $requested
     * @return array<int,int> document id => paisa (zero amounts dropped)
     */
    public function normalise(array $requested, Collection $open, int $availableCents): array
    {
        $byId = $open->keyBy(fn ($r) => $r['model']->id);
        $out = [];
        $sum = 0;

        foreach ($requested as $id => $amount) {
            $cents = Money::toCents($amount);
            if ($cents === 0) {
                continue;
            }
            if ($cents < 0) {
                throw new DomainException('Amounts applied to documents cannot be negative.');
            }
            $row = $byId[(int) $id] ?? throw new DomainException('One of the selected documents is not open for this party.');
            if ($cents > $row['balance']) {
                throw new DomainException(sprintf('%s only has %s outstanding; you cannot apply %s to it.', $row['label'], Money::fromCents($row['balance']), Money::fromCents($cents)));
            }
            $out[(int) $id] = $cents;
            $sum += $cents;
        }

        if ($sum > $availableCents) {
            throw new DomainException(sprintf('You applied %s to documents but only %s is available.', Money::fromCents($sum), Money::fromCents($availableCents)));
        }

        return $out;
    }
}
