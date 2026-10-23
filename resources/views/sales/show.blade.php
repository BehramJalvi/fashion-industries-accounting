@extends('layouts.app')
@section('title', $sale->hasInvoiceNumber() ? 'Invoice ' . $sale->invoice_no : 'Draft invoice')
@section('content')
@php
    $m = fn ($c) => number_format($c / 100, 2);
    $docBadge = ['draft' => 'warning', 'posted' => 'success', 'cancelled' => 'secondary'];
    $payBadge = ['unpaid' => 'danger', 'partial' => 'info', 'paid' => 'success'];
    $qty = fn ($n) => rtrim(rtrim(number_format((float) $n, 3), '0'), '.');
@endphp
<div class="card mb-3"><div class="card-body"><div class="row">
    <div class="col-lg-7">
        <h5>{{ $sale->hasInvoiceNumber() ? 'Invoice ' . $sale->invoice_no : 'Draft invoice' }}
            <span class="badge text-bg-{{ $docBadge[$sale->status] }}">{{ $sale->status }}</span>
            @if ($sale->isPosted())<span class="badge text-bg-{{ $payBadge[$summary['status']] ?? 'secondary' }}">{{ $summary['status'] }}</span>@endif</h5>
        <div class="fs-5">{{ $sale->buyer_name ?? $sale->customer?->name }}</div>
        <div class="text-muted">{{ $sale->sale_date->format('d-M-Y') }}@if ($sale->due_date) · Due {{ $sale->due_date->format('d-M-Y') }}@endif
            @if ($sale->gate_pass_no) · OGP {{ $sale->gate_pass_no }}@endif</div>
        <div class="small text-muted">@if ($sale->buyer_address){{ $sale->buyer_address }}<br>@endif
            @if ($sale->buyer_ntn)NTN {{ $sale->buyer_ntn }}@endif @if ($sale->buyer_strn) · STRN {{ $sale->buyer_strn }}@endif</div>
        @if ($sale->notes)<p class="mt-2 mb-1">{{ $sale->notes }}</p>@endif
        <div class="small text-muted mt-2">Entered by {{ $sale->creator?->name ?? '—' }}
            @if ($sale->journalEntry) · Journal <a href="{{ route('journal.show', $sale->journalEntry) }}">{{ $sale->journalEntry->entry_no }}</a>@endif
            @if ($sale->reversalEntry) · Reversal <a href="{{ route('journal.show', $sale->reversalEntry) }}">{{ $sale->reversalEntry->entry_no }}</a>@endif</div>
        @if ($sale->isCancelled())<div class="mt-2 text-danger small">Cancelled by {{ $sale->canceller?->name }}: {{ $sale->cancel_reason }}</div>@endif
    </div>
    <div class="col-lg-5 text-lg-end mt-3 mt-lg-0">
        @if ($sale->isDraft())
            @can('sales.post')<form method="POST" action="{{ route('sales.post', $sale) }}" class="d-inline">@csrf<button class="btn btn-primary">Post invoice</button></form>@endcan
            @can('sales.update')<a href="{{ route('sales.edit', $sale) }}" class="btn btn-outline-primary">Edit</a>
                <form method="POST" action="{{ route('sales.destroy', $sale) }}" class="d-inline" onsubmit="return confirm('Delete this draft?')">@csrf @method('DELETE')<button class="btn btn-outline-danger">Delete</button></form>@endcan
        @elseif ($sale->isPosted())
            @can('sales_returns.create')<a href="{{ route('sales-returns.create', ['sale_id' => $sale->id]) }}" class="btn btn-outline-secondary mb-2">Return goods</a>@endcan
            @can('sales.cancel')
                <form method="POST" action="{{ route('sales.cancel', $sale) }}" onsubmit="return confirm('Cancel this invoice? Stock and accounts will be reversed.')">@csrf
                    <input name="reason" class="form-control mb-2" placeholder="Reason (required)" required maxlength="200"><button class="btn btn-outline-danger">Cancel &amp; reverse</button></form>
            @endcan
        @endif
    </div>
</div></div></div>

<div class="card mb-3">
    <table class="table table-sm align-middle mb-0">
        <thead class="table-light"><tr><th>#</th><th>Description</th><th class="text-end">Qty</th><th class="text-end">Rate</th><th class="text-end">Tax %</th><th class="text-end">Value ex-tax</th><th class="text-end">Tax</th><th class="text-end">Value inc. tax</th></tr></thead>
        <tbody>
        @foreach ($sale->items as $n => $i)
            <tr><td>{{ $n + 1 }}</td><td>{{ $i->description ?: $i->product->name }}</td><td class="text-end">{{ $qty($i->qty) }}</td>
                <td class="text-end">{{ number_format((float) $i->unit_price, 2) }}</td><td class="text-end">{{ rtrim(rtrim($i->tax_rate, '0'), '.') ?: '0' }}%</td>
                <td class="text-end">{{ number_format((float) $i->line_total - (float) $i->tax_amount, 2) }}</td>
                <td class="text-end">{{ number_format((float) $i->tax_amount, 2) }}</td><td class="text-end">{{ number_format((float) $i->line_total, 2) }}</td></tr>
        @endforeach
        </tbody>
        <tfoot class="table-light">
            <tr><td colspan="5" class="text-end">Quantity {{ $qty($sale->items->sum('qty')) }}</td><td class="text-end">{{ number_format((float) $sale->subtotal - (float) $sale->discount_total, 2) }}</td>
                <td class="text-end">{{ number_format((float) $sale->tax_total, 2) }}</td><td class="text-end fw-bold">{{ number_format((float) $sale->total, 2) }}</td></tr>
            @if ((float) $sale->discount_total)<tr><td colspan="8" class="text-end small text-muted">Includes discount of {{ number_format((float) $sale->discount_total, 2) }} on a gross of {{ number_format((float) $sale->subtotal, 2) }}</td></tr>@endif
        </tfoot>
    </table>
</div>

@if ($sale->isPosted())
<div class="card mb-3"><div class="card-body row text-center">
    <div class="col"><div class="text-muted small">Received</div><div class="fs-5">{{ $m($summary['paid']) }}</div></div>
    <div class="col"><div class="text-muted small">Returned</div><div class="fs-5">{{ $m($summary['returned']) }}</div></div>
    <div class="col"><div class="text-muted small">Balance due</div><div class="fs-5 {{ $summary['balance'] < 0 ? 'text-danger' : '' }}">{{ $m($summary['balance']) }}</div></div>
    <div class="col"><div class="text-muted small">Cost of goods</div><div class="fs-5">{{ number_format((float) $sale->cogs_total, 2) }}</div></div>
    <div class="col"><div class="text-muted small">Gross profit</div><div class="fs-5">{{ number_format((float) $sale->grossProfit(), 2) }}</div></div>
</div>@if ($sale->receivedInto && (float) $sale->received_now)<div class="card-footer small text-muted">Received at sale: {{ number_format((float) $sale->received_now, 2) }} into {{ $sale->receivedInto->name }}</div>@endif</div>
@endif

@can('invoices.view')
@if ($sale->isPosted() || $sale->isCancelled())
@php
    $original = $sale->invoices->firstWhere('copy_type', 'original');
@endphp
<div class="card mb-3"><div class="card-header d-flex justify-content-between align-items-center"><span class="fw-semibold">Invoice copies</span>
    <span>
        @if ($sale->isCancelled())
            <a href="{{ route('sales.invoice', $sale) }}" class="btn btn-sm btn-outline-danger"><i class="bi bi-printer"></i> Cancelled copy</a>
        @else
            @if (! $original)<a href="{{ route('sales.invoice', [$sale, 'copy' => 'original']) }}" class="btn btn-sm btn-primary"><i class="bi bi-printer"></i> Print original</a>@endif
            <a href="{{ route('sales.invoice', [$sale, 'copy' => 'duplicate']) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-printer"></i> Duplicate</a>
            @if ($original)@can('invoices.reprint')<a href="{{ route('sales.invoice', [$sale, 'copy' => 'original']) }}" class="btn btn-sm btn-outline-secondary">Reprint original</a>@endcan @endif
        @endif
    </span></div>
    @if ($sale->invoices->isEmpty())<div class="card-body text-muted small">Nothing printed yet.</div>
    @else<table class="table table-sm mb-0"><tbody>
        @foreach ($sale->invoices as $inv)<tr><td class="text-capitalize">{{ $inv->copy_type }}</td><td>printed {{ $inv->print_count }} time{{ $inv->print_count == 1 ? '' : 's' }}</td><td class="text-muted">last by {{ $inv->printedBy?->name ?? '—' }} on {{ $inv->printed_at->format('d-M-Y H:i') }}</td></tr>@endforeach
    </tbody></table>@endif
</div>
@endif
@endcan

@if ($sale->returns->isNotEmpty())
<div class="card mb-3"><div class="card-header fw-semibold">Returns</div><ul class="list-group list-group-flush">
@foreach ($sale->returns as $r)<li class="list-group-item d-flex justify-content-between"><a href="{{ route('sales-returns.show', $r) }}">{{ $r->return_no }}</a><span>{{ number_format((float) $r->total, 2) }} · {{ $r->status }}</span></li>@endforeach
</ul></div>
@endif
<a href="{{ route('sales.index') }}" class="btn btn-light">Back</a>
@endsection
