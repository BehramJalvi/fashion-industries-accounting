@extends('layouts.app')
@section('title', $txn->txn_no)
@section('content')
<div class="card"><div class="card-body"><div class="row">
    <div class="col-md-7">
        <h5>{{ $txn->txn_no }} <span class="badge text-bg-{{ ['draft' => 'warning', 'posted' => 'success', 'cancelled' => 'secondary'][$txn->status] }}">{{ $txn->status }}</span></h5>
        <div class="fs-4">PKR {{ number_format((float) $txn->amount, 2) }}</div>
        <div>{{ $txn->txn_type === 'capital' ? 'Capital introduced by' : 'Drawings taken by' }} <strong>{{ $txn->owner->name }}</strong> · {{ $txn->txn_date->format('d-M-Y') }}</div>
        <div class="text-muted">{{ $txn->txn_type === 'capital' ? 'Into' : 'From' }} {{ $txn->account->name }}</div>
        @if ($txn->notes)<p class="mt-2 mb-1">{{ $txn->notes }}</p>@endif
        <div class="small text-muted mt-2">Entered by {{ $txn->creator?->name ?? '—' }}
            @if ($txn->journalEntry) · Journal <a href="{{ route('journal.show', $txn->journalEntry) }}">{{ $txn->journalEntry->entry_no }}</a>@endif
            @if ($txn->reversalEntry) · Reversal <a href="{{ route('journal.show', $txn->reversalEntry) }}">{{ $txn->reversalEntry->entry_no }}</a>@endif</div>
        @if ($txn->isCancelled())<div class="mt-2 text-danger small">Cancelled by {{ $txn->canceller?->name }}: {{ $txn->cancel_reason }}</div>@endif
    </div>
    <div class="col-md-5 text-md-end">
        @if ($txn->isDraft())
            @can('owner_transactions.post')<form method="POST" action="{{ route('owner-transactions.post', $txn) }}" class="d-inline">@csrf<button class="btn btn-primary">Post</button></form>@endcan
            @can('owner_transactions.update')<form method="POST" action="{{ route('owner-transactions.destroy', $txn) }}" class="d-inline" onsubmit="return confirm('Delete this draft?')">@csrf @method('DELETE')<button class="btn btn-outline-danger">Delete draft</button></form>@endcan
        @elseif ($txn->isPosted())
            @can('owner_transactions.cancel')
                <form method="POST" action="{{ route('owner-transactions.cancel', $txn) }}" onsubmit="return confirm('Cancel and reverse?')">@csrf
                    <input name="reason" class="form-control mb-2" placeholder="Reason (required)" required maxlength="200"><button class="btn btn-outline-danger">Cancel &amp; reverse</button></form>
            @endcan
        @endif
    </div>
</div></div></div>
<a href="{{ route('owner-transactions.index') }}" class="btn btn-light mt-3">Back</a>
@endsection
