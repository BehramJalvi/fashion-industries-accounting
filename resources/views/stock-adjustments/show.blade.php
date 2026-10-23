@extends('layouts.app')
@section('title', 'Adjustment ' . $adj->adjustment_no)
@section('content')
<div class="card mb-3"><div class="card-body"><div class="row">
    <div class="col-md-7">
        <h5>{{ $adj->adjustment_no }} <span class="badge text-bg-{{ ['draft' => 'warning', 'posted' => 'success', 'cancelled' => 'secondary'][$adj->status] }}">{{ $adj->status }}</span></h5>
        <div class="text-muted">{{ $adj->adjustment_date->format('d-M-Y') }} · {{ $adj->reason }}</div>
        @if ($adj->notes)<p class="mb-1 mt-2">{{ $adj->notes }}</p>@endif
        <div class="small text-muted mt-2">Entered by {{ $adj->creator?->name ?? '—' }}
            @if ($adj->journalEntry) · Journal <a href="{{ route('journal.show', $adj->journalEntry) }}">{{ $adj->journalEntry->entry_no }}</a>@endif
            @if ($adj->reversalEntry) · Reversal <a href="{{ route('journal.show', $adj->reversalEntry) }}">{{ $adj->reversalEntry->entry_no }}</a>@endif</div>
        @if ($adj->isCancelled())<div class="mt-2 text-danger small">Cancelled by {{ $adj->canceller?->name }}: {{ $adj->cancel_reason }}</div>@endif
    </div>
    <div class="col-md-5 text-md-end">
        @if ($adj->isDraft())
            @can('stock_adjustments.post')
                <form method="POST" action="{{ route('stock-adjustments.post', $adj) }}" class="d-inline">@csrf<button class="btn btn-primary">Post</button></form>
            @endcan
            @can('stock_adjustments.update')
                <form method="POST" action="{{ route('stock-adjustments.destroy', $adj) }}" class="d-inline" onsubmit="return confirm('Delete this draft?')">@csrf @method('DELETE')<button class="btn btn-outline-danger">Delete draft</button></form>
            @endcan
        @elseif ($adj->isPosted())
            @can('stock_adjustments.cancel')
                <form method="POST" action="{{ route('stock-adjustments.cancel', $adj) }}" onsubmit="return confirm('Cancel and reverse this adjustment?')">@csrf
                    <input name="reason" class="form-control mb-2" placeholder="Reason (required)" required maxlength="200"><button class="btn btn-outline-danger">Cancel &amp; reverse</button></form>
            @endcan
        @endif
    </div>
</div></div></div>

<div class="card mb-3"><div class="card-header fw-semibold">Items</div>
<table class="table table-sm align-middle mb-0"><thead class="table-light"><tr><th>Product</th><th>Direction</th><th class="text-end">Qty</th><th class="text-end">Unit cost</th></tr></thead><tbody>
@foreach ($adj->items as $i)
    <tr><td>{{ $i->product->code }} · {{ $i->product->name }}</td><td>{{ $i->direction === 'in' ? 'In (gain)' : 'Out (loss)' }}</td>
        <td class="text-end">{{ rtrim(rtrim(number_format((float) $i->qty, 3), '0'), '.') }} {{ $i->product->unit?->short_name }}</td>
        <td class="text-end">{{ $i->unit_cost !== null ? number_format((float) $i->unit_cost, 4) : ($adj->isDraft() ? 'average cost' : '—') }}</td></tr>
@endforeach</tbody></table></div>

@if ($movements->isNotEmpty())
<div class="card"><div class="card-header fw-semibold">Stock ledger movements</div>
<table class="table table-sm align-middle mb-0"><thead class="table-light"><tr><th>Product</th><th>Type</th><th class="text-end">In</th><th class="text-end">Out</th><th class="text-end">Cost</th><th class="text-end">Balance after</th></tr></thead><tbody>
@foreach ($movements as $m)
    <tr class="{{ $m->txn_type === 'reversal' ? 'table-secondary' : '' }}"><td>{{ $m->product->name }}</td><td>{{ $m->txn_type }}</td>
        <td class="text-end">{{ (float) $m->qty_in > 0 ? (float) $m->qty_in : '' }}</td><td class="text-end">{{ (float) $m->qty_out > 0 ? (float) $m->qty_out : '' }}</td>
        <td class="text-end">{{ number_format((float) $m->total_cost, 2) }}</td><td class="text-end">{{ (float) $m->balance_qty }}</td></tr>
@endforeach</tbody></table></div>
@endif
<a href="{{ route('stock-adjustments.index') }}" class="btn btn-light mt-3">Back</a>
@endsection
