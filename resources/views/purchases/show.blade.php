@extends('layouts.app')
@section('title', $purchase->purchase_no)
@section('content')
@php
    $m = fn ($c) => number_format($c / 100, 2);
    $docBadge = ['draft' => 'warning', 'posted' => 'success', 'cancelled' => 'secondary'];
    $payBadge = ['unpaid' => 'danger', 'partial' => 'info', 'paid' => 'success'];
@endphp
<div class="card mb-3"><div class="card-body"><div class="row">
    <div class="col-lg-7">
        <h5>{{ $purchase->purchase_no }}
            <span class="badge text-bg-{{ $docBadge[$purchase->status] }}">{{ $purchase->status }}</span>
            @if ($purchase->isPosted())<span class="badge text-bg-{{ $payBadge[$summary['status']] ?? 'secondary' }}">{{ $summary['status'] }}</span>@endif</h5>
        <div class="fs-5">{{ $purchase->supplier?->name ?? 'Walk-in / cash purchase' }}</div>
        <div class="text-muted">{{ $purchase->purchase_date->format('d-M-Y') }}@if ($purchase->supplier_ref) · Invoice {{ $purchase->supplier_ref }}@endif
            @if ($purchase->due_date) · Due {{ $purchase->due_date->format('d-M-Y') }}@endif</div>
        @if ($purchase->fundedByEmployee)<div class="mt-1"><span class="badge text-bg-light border">Paid for by {{ $purchase->fundedByEmployee->name }} (to be reimbursed)</span></div>@endif
        @if ($purchase->notes)<p class="mt-2 mb-1">{{ $purchase->notes }}</p>@endif
        <div class="small text-muted mt-2">Entered by {{ $purchase->creator?->name ?? '—' }}
            @if ($purchase->journalEntry) · Journal <a href="{{ route('journal.show', $purchase->journalEntry) }}">{{ $purchase->journalEntry->entry_no }}</a>@endif
            @if ($purchase->reversalEntry) · Reversal <a href="{{ route('journal.show', $purchase->reversalEntry) }}">{{ $purchase->reversalEntry->entry_no }}</a>@endif</div>
        @if ($purchase->isCancelled())<div class="mt-2 text-danger small">Cancelled by {{ $purchase->canceller?->name }}: {{ $purchase->cancel_reason }}</div>@endif
    </div>
    <div class="col-lg-5 text-lg-end mt-3 mt-lg-0">
        @if ($purchase->isPosted() || $purchase->isCancelled())
            @can('purchases.view')
                <a href="{{ route('purchases.invoice', $purchase) }}" class="btn btn-outline-secondary mb-2"><i class="bi bi-printer"></i> Purchase document</a>
            @endcan
        @endif
        @if ($purchase->isDraft())
            @can('purchases.post')<form method="POST" action="{{ route('purchases.post', $purchase) }}" class="d-inline">@csrf<button class="btn btn-primary">Post</button></form>@endcan
            @can('purchases.update')<a href="{{ route('purchases.edit', $purchase) }}" class="btn btn-outline-primary">Edit</a>
                <form method="POST" action="{{ route('purchases.destroy', $purchase) }}" class="d-inline" onsubmit="return confirm('Delete this draft?')">@csrf @method('DELETE')<button class="btn btn-outline-danger">Delete</button></form>@endcan
        @elseif ($purchase->isPosted())
            @can('purchase_returns.create')<a href="{{ route('purchase-returns.create', ['purchase_id' => $purchase->id]) }}" class="btn btn-outline-secondary mb-2">Return goods</a>@endcan
            @can('purchases.cancel')
                <form method="POST" action="{{ route('purchases.cancel', $purchase) }}" onsubmit="return confirm('Cancel this purchase? Stock and accounts will be reversed.')">@csrf
                    <input name="reason" class="form-control mb-2" placeholder="Reason (required)" required maxlength="200"><button class="btn btn-outline-danger">Cancel &amp; reverse</button></form>
            @endcan
        @endif
    </div>
</div></div></div>

@if ($purchase->invoicePrint)
<div class="card mb-3"><div class="card-body small text-muted">
    Purchase document printed {{ $purchase->invoicePrint->print_count }} time{{ $purchase->invoicePrint->print_count === 1 ? '' : 's' }}.
    Last by {{ $purchase->invoicePrint->printedBy?->name ?? '—' }} on {{ $purchase->invoicePrint->printed_at->format('d-M-Y H:i') }}.
</div></div>
@endif

<div class="card mb-3">
    <table class="table table-sm align-middle mb-0">
        <thead class="table-light"><tr><th>Product</th><th class="text-end">Qty</th><th class="text-end">Unit cost</th><th class="text-end">Discount</th><th class="text-end">Tax</th><th class="text-end">Line total</th></tr></thead>
        <tbody>
        @foreach ($purchase->items as $i)
            <tr><td>{{ $i->product->code }} · {{ $i->product->name }}</td>
                <td class="text-end">{{ rtrim(rtrim(number_format((float) $i->qty, 3), '0'), '.') }} {{ $i->product->unit?->short_name }}</td>
                <td class="text-end">{{ number_format((float) $i->unit_cost, 2) }}</td>
                <td class="text-end">{{ (float) $i->discount ? number_format((float) $i->discount, 2) : '' }}</td>
                <td class="text-end">{{ (float) $i->tax_amount ? number_format((float) $i->tax_amount, 2) . ' (' . rtrim(rtrim($i->tax_rate, '0'), '.') . '%)' : '' }}</td>
                <td class="text-end">{{ number_format((float) $i->line_total, 2) }}</td></tr>
        @endforeach
        </tbody>
        <tfoot class="table-light">
            <tr><td colspan="5" class="text-end">Subtotal</td><td class="text-end">{{ number_format((float) $purchase->subtotal, 2) }}</td></tr>
            @if ((float) $purchase->discount_total)<tr><td colspan="5" class="text-end">Discount</td><td class="text-end">−{{ number_format((float) $purchase->discount_total, 2) }}</td></tr>@endif
            @if ((float) $purchase->tax_total)<tr><td colspan="5" class="text-end">Tax</td><td class="text-end">{{ number_format((float) $purchase->tax_total, 2) }}</td></tr>@endif
            <tr class="fw-bold"><td colspan="5" class="text-end">Total</td><td class="text-end">{{ number_format((float) $purchase->total, 2) }}</td></tr>
        </tfoot>
    </table>
</div>

@if ($purchase->isPosted())
<div class="card mb-3"><div class="card-body row text-center">
    <div class="col"><div class="text-muted small">Paid</div><div class="fs-5">{{ $m($summary['paid']) }}</div></div>
    <div class="col"><div class="text-muted small">Returned</div><div class="fs-5">{{ $m($summary['returned']) }}</div></div>
    <div class="col"><div class="text-muted small">Balance owed</div><div class="fs-5 {{ $summary['balance'] < 0 ? 'text-danger' : '' }}">{{ $m($summary['balance']) }}</div></div>
</div>@if ($purchase->paidFromAccount && (float) $purchase->paid_now)<div class="card-footer small text-muted">Paid at purchase: {{ number_format((float) $purchase->paid_now, 2) }} from {{ $purchase->paidFromAccount->name }}</div>@endif</div>
@endif

@if ($purchase->returns->isNotEmpty())
<div class="card mb-3"><div class="card-header fw-semibold">Returns</div><ul class="list-group list-group-flush">
@foreach ($purchase->returns as $r)<li class="list-group-item d-flex justify-content-between"><a href="{{ route('purchase-returns.show', $r) }}">{{ $r->return_no }}</a><span>{{ number_format((float) $r->total, 2) }} · {{ $r->status }}</span></li>@endforeach
</ul></div>
@endif
<a href="{{ route('purchases.index') }}" class="btn btn-light">Back</a>
@endsection
