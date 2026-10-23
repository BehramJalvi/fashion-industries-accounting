<?php

namespace App\Services\Reports;

use App\Models\Customer;
use App\Models\Employee;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Supplier;
use App\Services\Accounting\PartyLedger;
use App\Services\Purchasing\PurchaseSummary;
use App\Services\Sales\SaleSummary;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Outstanding receivables / payables with ageing by days overdue (against each invoice's due date).
 * Open amounts are today's position; $asOf only decides which ageing bucket an invoice falls in.
 * "Other" = the part of a party's ledger balance that is not on an open invoice (opening balance, advances, money on account),
 * so each row's Total equals the party's ledger balance and the grand total equals the control account.
 */
class AgingService
{
    public const BUCKETS = ['current' => 'Not yet due', 'd30' => '1-30 days', 'd60' => '31-60 days', 'd90' => '61-90 days', 'd90p' => 'Over 90 days'];

    public function __construct(private PartyLedger $ledger)
    {
    }

    /** @return array{rows:array<int,array>, totals:array<string,int>} */
    public function receivables(Carbon $asOf): array
    {
        $sales = Sale::where('status', 'posted')->get();
        $summary = SaleSummary::for($sales);
        $items = $sales->map(fn (Sale $s) => ['party' => $s->customer_id, 'balance' => $summary[$s->id]['balance'], 'due' => $s->due_date ?? $s->sale_date])
            ->filter(fn ($i) => $i['balance'] > 0);

        $proto = new Customer();
        $ledger = $this->ledger->balances('customer', $proto->ledgerControlCodes(), $proto->ledgerNature());

        return $this->build($items, $ledger, Customer::class, $asOf);
    }

    /** Suppliers. Purchases an employee paid for are owed to the employee, so they are reported under employees(). */
    public function payables(Carbon $asOf): array
    {
        $purchases = Purchase::where('status', 'posted')->whereNull('funded_by_employee_id')->whereNotNull('supplier_id')->get();
        $summary = PurchaseSummary::for($purchases);
        $items = $purchases->map(fn (Purchase $p) => ['party' => $p->supplier_id, 'balance' => $summary[$p->id]['balance'], 'due' => $p->due_date ?? $p->purchase_date])
            ->filter(fn ($i) => $i['balance'] > 0);

        $proto = new Supplier();
        $ledger = $this->ledger->balances('supplier', $proto->ledgerControlCodes(), $proto->ledgerNature());

        return $this->build($items, $ledger, Supplier::class, $asOf);
    }

    /** Employees we owe (salary, money they spent for the business). */
    public function employees(Carbon $asOf): array
    {
        $purchases = Purchase::where('status', 'posted')->whereNotNull('funded_by_employee_id')->get();
        $summary = PurchaseSummary::for($purchases);
        $items = $purchases->map(fn (Purchase $p) => ['party' => $p->funded_by_employee_id, 'balance' => $summary[$p->id]['balance'], 'due' => $p->due_date ?? $p->purchase_date])
            ->filter(fn ($i) => $i['balance'] > 0);

        $proto = new Employee();
        $ledger = $this->ledger->balances('employee', $proto->ledgerControlCodes(), $proto->ledgerNature());

        return $this->build($items, $ledger, Employee::class, $asOf);
    }

    /** @param Collection<int, array{party:int, balance:int, due:Carbon}> $items  @param array<int,int> $ledger */
    private function build(Collection $items, array $ledger, string $partyClass, Carbon $asOf): array
    {
        $rows = [];
        foreach ($items->groupBy('party') as $partyId => $group) {
            $row = $this->emptyRow();
            foreach ($group as $i) {
                $row[$this->bucket($i['due'], $asOf)] += $i['balance'];
                $row['open'] += $i['balance'];
            }
            $rows[$partyId] = $row;
        }

        foreach ($ledger as $partyId => $balance) {              // parties with a balance but nothing open (opening balance, advances)
            $rows[$partyId] ??= $this->emptyRow();
        }

        $names = $partyClass::withTrashed()->whereIn('id', array_keys($rows))->pluck('name', 'id');
        $out = [];
        $totals = $this->emptyRow() + ['total' => 0, 'other' => 0];
        $totals['other'] = 0;

        foreach ($rows as $partyId => $row) {
            $total = $ledger[$partyId] ?? 0;
            $row['total'] = $total;
            $row['other'] = $total - $row['open'];
            if ($row['total'] === 0 && $row['open'] === 0) {
                continue;
            }
            $row['name'] = $names[$partyId] ?? "#{$partyId}";
            $row['id'] = $partyId;
            $out[] = $row;
            foreach (['current', 'd30', 'd60', 'd90', 'd90p', 'open', 'other', 'total'] as $k) {
                $totals[$k] += $row[$k];
            }
        }
        usort($out, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        return ['rows' => $out, 'totals' => $totals];
    }

    private function emptyRow(): array
    {
        return ['current' => 0, 'd30' => 0, 'd60' => 0, 'd90' => 0, 'd90p' => 0, 'open' => 0];
    }

    private function bucket(Carbon $due, Carbon $asOf): string
    {
        $overdue = (int) floor(($asOf->copy()->startOfDay()->timestamp - $due->copy()->startOfDay()->timestamp) / 86400);

        return match (true) {
            $overdue <= 0 => 'current', $overdue <= 30 => 'd30', $overdue <= 60 => 'd60', $overdue <= 90 => 'd90', default => 'd90p',
        };
    }
}
