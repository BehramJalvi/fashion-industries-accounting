@extends('layouts.app')
@section('title', 'Production ' . $order->order_no)
@section('content')
@php
    $statusBadge = ['draft' => 'warning', 'posted' => 'success', 'cancelled' => 'secondary'];
    $consumedCost = $movements->where('txn_type', 'production_consume')->sum(fn ($movement) => (float) $movement->total_cost);
    $producedCost = $movements->where('txn_type', 'production_output')->sum(fn ($movement) => (float) $movement->total_cost);
@endphp
<div class="card mb-3"><div class="card-body"><div class="row">
    <div class="col-md-7">
        <h5>{{ $order->order_no }} <span class="badge text-bg-{{ $statusBadge[$order->status] }}">{{ $order->status }}</span></h5>
        <div class="text-muted">{{ $order->order_date->format('d-M-Y') }}@if ($order->processor) · Processor: {{ $order->processor->name }}@endif</div>
        @if ($order->notes)<p class="mb-1 mt-2">{{ $order->notes }}</p>@endif
        <div class="small text-muted mt-2">Entered by {{ $order->creator?->name ?? '—' }}
            @if ($order->journalEntry) · Journal <a href="{{ route('journal.show', $order->journalEntry) }}">{{ $order->journalEntry->entry_no }}</a>@endif
            @if ($order->reversalEntry) · Reversal <a href="{{ route('journal.show', $order->reversalEntry) }}">{{ $order->reversalEntry->entry_no }}</a>@endif</div>
        @if ($order->isCancelled())<div class="mt-2 text-danger small">Cancelled by {{ $order->canceller?->name }}: {{ $order->cancel_reason }}</div>@endif
    </div>
    <div class="col-md-5 text-md-end">
        @if ($order->isDraft())
            @can('production_orders.post')<form method="POST" action="{{ route('production-orders.post', $order) }}" class="d-inline">@csrf<button class="btn btn-primary">Post</button></form>@endcan
            @can('production_orders.update')
                <form method="POST" action="{{ route('production-orders.destroy', $order) }}" class="d-inline" onsubmit="return confirm('Delete this draft production transaction?')">@csrf @method('DELETE')<button class="btn btn-outline-danger">Delete draft</button></form>
            @endcan
        @elseif ($order->isPosted())
            @can('production_orders.cancel')
                <form method="POST" action="{{ route('production-orders.cancel', $order) }}" onsubmit="return confirm('Cancel and reverse this production transaction?')">@csrf
                    <input name="reason" class="form-control mb-2" placeholder="Reason (required)" required maxlength="200"><button class="btn btn-outline-danger">Cancel &amp; reverse</button></form>
            @endcan
        @endif
    </div>
</div></div></div>

<div class="card mb-3"><div class="card-header fw-semibold">Materials and output</div>
<div class="table-responsive"><table class="table table-sm align-middle mb-0">
    <thead class="table-light"><tr><th>Product</th><th>Movement</th><th class="text-end">Quantity</th><th class="text-end">Unit cost</th></tr></thead>
    <tbody>
    @foreach ($order->inputs as $input)
        <tr><td>{{ $input->product->code }} · {{ $input->product->name }}</td><td>Raw material consumed</td>
            <td class="text-end">{{ number_format((float) $input->qty, 3) }} {{ $input->product->unit?->short_name }}</td>
            <td class="text-end">{{ $input->unit_cost !== null ? number_format((float) $input->unit_cost, 4) : 'Moving average' }}</td></tr>
    @endforeach
    @foreach ($order->outputs as $output)
        <tr class="table-light"><td>{{ $output->product->code }} · {{ $output->product->name }}</td><td>Finished product received</td>
            <td class="text-end">{{ number_format((float) $output->qty, 3) }} {{ $output->product->unit?->short_name }}</td>
            <td class="text-end">{{ $output->unit_cost !== null ? number_format((float) $output->unit_cost, 4) : 'Calculated when posted' }}</td></tr>
    @endforeach
    </tbody>
    <tfoot class="table-light">
        <tr><td colspan="3" class="text-end">Raw material cost</td><td class="text-end">{{ number_format($consumedCost, 2) }}</td></tr>
        <tr><td colspan="3" class="text-end">Processing fee included in thread cost</td><td class="text-end">{{ number_format((float) $order->processing_fee, 2) }}</td></tr>
        @if ($order->isPosted() || $order->isCancelled())<tr class="fw-semibold"><td colspan="3" class="text-end">Finished inventory cost</td><td class="text-end">{{ number_format($producedCost, 2) }}</td></tr>@endif
    </tfoot>
</table></div>
@if ((float) $order->processing_fee > 0)
    <div class="card-footer small text-muted">
        @if ($order->paidFromAccount)Processing fee paid from {{ $order->paidFromAccount->name }}.
        @else Processing fee payable to {{ $order->processor?->name ?? 'processor' }}.@endif
    </div>
@endif
</div>

@if ($movements->isNotEmpty())
<div class="card"><div class="card-header fw-semibold">Stock ledger movements</div>
<div class="table-responsive"><table class="table table-sm align-middle mb-0">
    <thead class="table-light"><tr><th>Product</th><th>Type</th><th class="text-end">In</th><th class="text-end">Out</th><th class="text-end">Movement cost</th><th class="text-end">Balance after</th></tr></thead>
    <tbody>@foreach ($movements as $movement)
        <tr class="{{ $movement->txn_type === 'reversal' ? 'table-secondary' : '' }}"><td>{{ $movement->product->name }}</td><td>{{ $movement->txn_type }}</td>
            <td class="text-end">{{ (float) $movement->qty_in > 0 ? number_format((float) $movement->qty_in, 3) : '' }}</td>
            <td class="text-end">{{ (float) $movement->qty_out > 0 ? number_format((float) $movement->qty_out, 3) : '' }}</td>
            <td class="text-end">{{ number_format((float) $movement->total_cost, 2) }}</td>
            <td class="text-end">{{ number_format((float) $movement->balance_qty, 3) }}</td></tr>
    @endforeach</tbody>
</table></div></div>
@endif
<a href="{{ route('production-orders.index') }}" class="btn btn-light mt-3">Back</a>
@endsection
