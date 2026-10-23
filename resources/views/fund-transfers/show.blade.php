@extends('layouts.app')
@section('title', $transfer->transfer_no)
@section('content')
<div class="card"><div class="card-body"><div class="row">
    <div class="col-lg-7">
        <h5>{{ $transfer->transfer_no }} <span class="badge text-bg-{{ ['draft' => 'warning', 'posted' => 'success', 'cancelled' => 'secondary'][$transfer->status] }}">{{ $transfer->status }}</span></h5>
        <div class="fs-4">PKR {{ number_format((float) $transfer->amount, 2) }}</div>
        <div>{{ $transfer->fromAccount->name }} <i class="bi bi-arrow-right"></i> {{ $transfer->toAccount->name }} · {{ $transfer->transfer_date->format('d-M-Y') }}</div>
        @if ($transfer->reference)<div class="text-muted">Ref {{ $transfer->reference }}</div>@endif
        @if ($transfer->notes)<p class="mt-2 mb-1">{{ $transfer->notes }}</p>@endif
        <div class="small text-muted mt-2">Entered by {{ $transfer->creator?->name ?? '—' }}
            @if ($transfer->journalEntry) · Journal <a href="{{ route('journal.show', $transfer->journalEntry) }}">{{ $transfer->journalEntry->entry_no }}</a>@endif
            @if ($transfer->reversalEntry) · Reversal <a href="{{ route('journal.show', $transfer->reversalEntry) }}">{{ $transfer->reversalEntry->entry_no }}</a>@endif</div>
        @if ($transfer->isCancelled())<div class="mt-2 text-danger small">Cancelled by {{ $transfer->canceller?->name }}: {{ $transfer->cancel_reason }}</div>@endif
    </div>
    <div class="col-lg-5 text-lg-end mt-3 mt-lg-0">
        @if ($transfer->isDraft())
            @can('fund_transfers.update')<a href="{{ route('fund-transfers.edit', $transfer) }}" class="btn btn-outline-secondary">Edit draft</a>@endcan
            @can('fund_transfers.post')<form method="POST" action="{{ route('fund-transfers.post', $transfer) }}" class="d-inline">@csrf<button class="btn btn-primary">Post</button></form>@endcan
            @can('fund_transfers.update')<form method="POST" action="{{ route('fund-transfers.destroy', $transfer) }}" class="d-inline" onsubmit="return confirm('Delete this draft?')">@csrf @method('DELETE')<button class="btn btn-outline-danger">Delete draft</button></form>@endcan
        @elseif ($transfer->isPosted())
            @can('fund_transfers.cancel')
                <form method="POST" action="{{ route('fund-transfers.cancel', $transfer) }}" onsubmit="return confirm('Cancel and reverse this transfer?')">@csrf
                    <input name="reason" class="form-control mb-2" placeholder="Reason (required)" required maxlength="200"><button class="btn btn-outline-danger">Cancel &amp; reverse</button></form>
            @endcan
        @endif
    </div>
</div></div></div>
<a href="{{ route('fund-transfers.index') }}" class="btn btn-light mt-3">Back</a>
@endsection
