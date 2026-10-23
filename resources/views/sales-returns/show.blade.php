@extends('layouts.app')
@section('title', $return->return_no)
@section('content')
<div class="card mb-3"><div class="card-body"><div class="row">
    <div class="col-lg-7">
        <h5>{{ $return->return_no }} <span class="badge text-bg-{{ ['draft' => 'warning', 'posted' => 'success', 'cancelled' => 'secondary'][$return->status] }}">{{ $return->status }}</span></h5>
        <div class="fs-5">PKR {{ number_format((float) $return->total, 2) }}</div>
        <div>Against invoice <a href="{{ route('sales.show', $return->sale) }}">{{ $return->sale->invoice_no }}</a> · {{ $return->sale->customer?->name }} · {{ $return->return_date->format('d-M-Y') }}</div>
        @if ($return->reason)<div class="text-muted">{{ $return->reason }}</div>@endif
        <div class="mt-1">@if ((float) $return->refund_now)Refunded now: {{ number_format((float) $return->refund_now, 2) }} from {{ $return->refundAccount?->name }}.
            @endif @if ((float) $return->total - (float) $return->refund_now > 0)Credit note: {{ number_format((float) $return->total - (float) $return->refund_now, 2) }} deducted from what the customer owes.@endif</div>
        <div class="small text-muted mt-2">Entered by {{ $return->creator?->name ?? '—' }}
            @if ($return->journalEntry) · Journal <a href="{{ route('journal.show', $return->journalEntry) }}">{{ $return->journalEntry->entry_no }}</a>@endif
            @if ($return->reversalEntry) · Reversal <a href="{{ route('journal.show', $return->reversalEntry) }}">{{ $return->reversalEntry->entry_no }}</a>@endif</div>
        @if ($return->isCancelled())<div class="mt-2 text-danger small">Cancelled by {{ $return->canceller?->name }}: {{ $return->cancel_reason }}</div>@endif
    </div>
    <div class="col-lg-5 text-lg-end mt-3 mt-lg-0">
        @if ($return->isDraft())
            @can('sales_returns.post')<form method="POST" action="{{ route('sales-returns.post', $return) }}" class="d-inline">@csrf<button class="btn btn-primary">Post</button></form>@endcan
            @can('sales_returns.update')<form method="POST" action="{{ route('sales-returns.destroy', $return) }}" class="d-inline" onsubmit="return confirm('Delete this draft?')">@csrf @method('DELETE')<button class="btn btn-outline-danger">Delete draft</button></form>@endcan
        @elseif ($return->isPosted())
            @can('sales_returns.cancel')
                <form method="POST" action="{{ route('sales-returns.cancel', $return) }}" onsubmit="return confirm('Cancel this return? Stock and accounts will be reversed.')">@csrf
                    <input name="reason" class="form-control mb-2" placeholder="Reason (required)" required maxlength="200"><button class="btn btn-outline-danger">Cancel &amp; reverse</button></form>
            @endcan
        @endif
    </div>
</div></div></div>

<div class="card"><table class="table table-sm align-middle mb-0">
    <thead class="table-light"><tr><th>Product</th><th class="text-end">Qty</th><th class="text-end">Price</th><th class="text-end">Tax</th><th class="text-end">Total</th><th class="text-end">Cost back into stock</th></tr></thead>
    <tbody>@foreach ($return->items as $i)
        <tr><td>{{ $i->product->code }} · {{ $i->product->name }}</td><td class="text-end">{{ rtrim(rtrim(number_format((float) $i->qty, 3), '0'), '.') }} {{ $i->product->unit?->short_name }}</td>
            <td class="text-end">{{ number_format((float) $i->unit_price, 2) }}</td><td class="text-end">{{ (float) $i->tax_amount ? number_format((float) $i->tax_amount, 2) : '' }}</td>
            <td class="text-end">{{ number_format((float) $i->line_total, 2) }}</td><td class="text-end">{{ number_format((float) $i->cogs_amount, 2) }}</td></tr>
    @endforeach</tbody>
    <tfoot class="table-light fw-semibold"><tr><td colspan="4" class="text-end">Total</td><td class="text-end">{{ number_format((float) $return->total, 2) }}</td><td></td></tr></tfoot>
</table></div>
<a href="{{ route('sales-returns.index') }}" class="btn btn-light mt-3">Back</a>
@endsection
