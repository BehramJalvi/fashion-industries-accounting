<?php

namespace App\Services\Reports;

use App\Models\Customer;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Receipt;
use App\Models\Sale;
use App\Models\SalesReturn;
use App\Models\Supplier;
use App\Models\Employee;
use App\Services\Purchasing\PurchaseSummary;
use App\Services\Sales\SaleSummary;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;

/**
 * One engine for the listing reports (sales, purchases, returns, expenses, receipts, payments): a date range, a status filter and totals.
 * Each cell is either text or ['m' => paisa] so the screen and the CSV can format money and add up the totals.
 */
class DocumentReportService
{
    public const LIMIT = 5000;

    /** type => [title, model, date column, party filter column] */
    private const TYPES = [
        'sales'            => ['Sales', Sale::class, 'sale_date', 'customer_id'],
        'purchases'        => ['Purchases', Purchase::class, 'purchase_date', 'supplier_id'],
        'sales-returns'    => ['Sales returns', SalesReturn::class, 'return_date', 'customer_id'],
        'purchase-returns' => ['Purchase returns', PurchaseReturn::class, 'return_date', 'supplier_id'],
        'expenses'         => ['Expenses', Expense::class, 'expense_date', null],
        'receipts'         => ['Receipts', Receipt::class, 'receipt_date', 'customer_id'],
        'payments'         => ['Payments', Payment::class, 'payment_date', null],
    ];

    public static function types(): array
    {
        return array_map(fn ($t) => $t[0], self::TYPES);
    }

    /** Customers or suppliers offered in the party filter for this report (null = no party filter). */
    public function partyFilter(string $type): ?array
    {
        return match (self::TYPES[$type][3] ?? null) {
            'customer_id' => ['label' => 'Customer', 'list' => Customer::orderBy('name')->get(['id', 'name'])],
            'supplier_id' => ['label' => 'Supplier', 'list' => Supplier::orderBy('name')->get(['id', 'name'])],
            default => null,
        };
    }

    /** @return array{title:string, headers:array<int,array{0:string,1:bool}>, rows:array<int,array>, totals:array<int,int>, truncated:bool} */
    public function build(string $type, string $from, string $to, string $status = 'posted', ?int $partyId = null): array
    {
        [$title, $class, $dateCol, $partyCol] = self::TYPES[$type];

        $query = $class::query()->whereDate($dateCol, '>=', $from)->whereDate($dateCol, '<=', $to)
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($partyId && $partyCol, fn ($q) => $q->where($partyCol, $partyId))
            ->orderBy($dateCol)->orderBy('id');
        $docs = $query->limit(self::LIMIT + 1)->get();
        $truncated = $docs->count() > self::LIMIT;
        $docs = $docs->take(self::LIMIT);

        [$headers, $rows] = match ($type) {
            'sales' => $this->sales($docs),
            'purchases' => $this->purchases($docs),
            'sales-returns' => $this->returns($docs, 'sale', 'return_no', Customer::class, 'customer_id'),
            'purchase-returns' => $this->returns($docs, 'purchase', 'return_no', Supplier::class, 'supplier_id'),
            'expenses' => $this->expenses($docs),
            'receipts' => $this->settlements($docs, 'receipt'),
            'payments' => $this->settlements($docs, 'payment'),
        };

        $totals = [];
        foreach ($headers as $i => [$label, $isMoney]) {
            $totals[$i] = $isMoney ? array_sum(array_map(fn ($r) => $r[$i]['m'] ?? 0, $rows)) : 0;
        }

        return ['title' => $title, 'headers' => $headers, 'rows' => $rows, 'totals' => $totals, 'truncated' => $truncated];
    }

    private function sales($docs): array
    {
        $summary = SaleSummary::for($docs);
        $names = Customer::withTrashed()->whereIn('id', $docs->pluck('customer_id'))->pluck('name', 'id');
        $headers = [['Date', false], ['Invoice', false], ['Customer', false], ['OGP', false], ['Status', false], ['Value ex-tax', true], ['Sales tax', true], ['Total', true], ['Cost of goods', true], ['Gross profit', true], ['Received', true], ['Balance', true]];
        $rows = $docs->map(function (Sale $s) use ($summary, $names) {
            $ex = Money::toCents($s->subtotal) - Money::toCents($s->discount_total);
            $live = $s->status === 'posted';

            return [$s->sale_date->format('d-M-Y'), $s->hasInvoiceNumber() ? $s->invoice_no : 'Draft', $names[$s->customer_id] ?? '', $s->gate_pass_no ?? '', $s->status,
                ['m' => $ex], ['m' => Money::toCents($s->tax_total)], ['m' => Money::toCents($s->total)], ['m' => Money::toCents($s->cogs_total)], ['m' => $ex - Money::toCents($s->cogs_total)],
                ['m' => $live ? $summary[$s->id]['paid'] : 0], ['m' => $live ? $summary[$s->id]['balance'] : 0]];
        })->all();

        return [$headers, $rows];
    }

    private function purchases($docs): array
    {
        $summary = PurchaseSummary::for($docs);
        $names = Supplier::withTrashed()->whereIn('id', $docs->pluck('supplier_id'))->pluck('name', 'id');
        $headers = [['Date', false], ['No.', false], ['Supplier', false], ['Supplier invoice', false], ['Status', false], ['Value ex-tax', true], ['Input tax', true], ['Total', true], ['Paid', true], ['Balance', true]];
        $rows = $docs->map(function (Purchase $p) use ($summary, $names) {
            $live = $p->status === 'posted';

            return [$p->purchase_date->format('d-M-Y'), $p->purchase_no, $names[$p->supplier_id] ?? ($p->supplier_id ? '' : 'Walk-in'), $p->supplier_ref ?? '', $p->status,
                ['m' => Money::toCents($p->subtotal) - Money::toCents($p->discount_total)], ['m' => Money::toCents($p->tax_total)], ['m' => Money::toCents($p->total)],
                ['m' => $live ? $summary[$p->id]['paid'] : 0], ['m' => $live ? $summary[$p->id]['balance'] : 0]];
        })->all();

        return [$headers, $rows];
    }

    private function returns($docs, string $kind, string $noCol, string $partyClass, string $partyCol): array
    {
        $names = $partyClass::withTrashed()->whereIn('id', $docs->pluck($partyCol))->pluck('name', 'id');
        $headers = [['Date', false], ['No.', false], [$kind === 'sale' ? 'Customer' : 'Supplier', false], ['Status', false], ['Value ex-tax', true], ['Tax', true], ['Total', true], ['Refunded now', true]];
        $rows = $docs->map(fn (Model $r) => [$r->return_date->format('d-M-Y'), $r->{$noCol}, $names[$r->{$partyCol}] ?? '', $r->status,
            ['m' => Money::toCents($r->subtotal)], ['m' => Money::toCents($r->tax_total)], ['m' => Money::toCents($r->total)], ['m' => Money::toCents($r->refund_now)]])->all();

        return [$headers, $rows];
    }

    private function expenses($docs): array
    {
        $docs->load(['expenseAccount', 'paidFromAccount']);
        $headers = [['Date', false], ['No.', false], ['Type', false], ['Description', false], ['Paid from / owed to', false], ['Status', false], ['Amount', true], ['Input tax', true], ['Total', true]];
        $rows = $docs->map(function (Expense $e) {
            $who = $e->paidFromAccount?->name ?? ($e->payee()?->name ? 'Owed to ' . $e->payee()->name : '');

            return [$e->expense_date->format('d-M-Y'), $e->expense_no, $e->expenseAccount?->name ?? '', $e->description, $who, $e->status,
                ['m' => Money::toCents($e->amount)], ['m' => Money::toCents($e->tax_amount)], ['m' => Money::toCents($e->amount) + Money::toCents($e->tax_amount)]];
        })->all();

        return [$headers, $rows];
    }

    private function settlements($docs, string $kind): array
    {
        $docs->load('account');
        $applied = PaymentAllocation::where('document_type', $kind)->where('is_active', true)->whereIn('document_id', $docs->pluck('id'))
            ->selectRaw('document_id, SUM(amount) as a')->groupBy('document_id')->pluck('a', 'document_id');
        $customers = $kind === 'receipt' ? Customer::withTrashed()->whereIn('id', $docs->pluck('customer_id'))->pluck('name', 'id') : collect();
        $headers = [['Date', false], ['No.', false], [$kind === 'receipt' ? 'Customer' : 'Paid to', false], ['Method', false], ['Account', false], ['Status', false], ['Amount', true], ['Applied to documents', true], ['On account', true]];

        $rows = $docs->map(function (Model $d) use ($kind, $applied, $customers) {
            $name = $kind === 'receipt' ? ($customers[$d->customer_id] ?? '') : (($d->payee_type === 'employee' ? Employee::withTrashed() : Supplier::withTrashed())->find($d->payee_id)?->name ?? '');
            $amount = Money::toCents($d->amount);
            $app = Money::toCents($applied[$d->id] ?? 0);
            $date = $kind === 'receipt' ? $d->receipt_date : $d->payment_date;

            return [$date->format('d-M-Y'), $kind === 'receipt' ? $d->receipt_no : $d->payment_no, $name, $d->method, $d->account?->name ?? '', $d->status,
                ['m' => $amount], ['m' => $app], ['m' => $d->status === 'posted' ? $amount - $app : 0]];
        })->all();

        return [$headers, $rows];
    }
}
