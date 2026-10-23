@extends('layouts.app')
@section('title', $expense->expense_no)
@section('content')
<div class="card"><div class="card-body"><div class="row">
    <div class="col-lg-7">
        <h5>{{ $expense->expense_no }} <span class="badge text-bg-{{ ['draft' => 'warning', 'posted' => 'success', 'cancelled' => 'secondary'][$expense->status] }}">{{ $expense->status }}</span></h5>
        <div class="fs-4">PKR {{ number_format((float) $expense->amount + (float) $expense->tax_amount, 2) }}</div>
        <div>{{ $expense->expenseAccount->name }} · {{ $expense->expense_date->format('d-M-Y') }}</div>
        <div class="mt-1">{{ $expense->description }}</div>
        <div class="text-muted">@if ((float) $expense->tax_amount)Amount {{ number_format((float) $expense->amount, 2) }} + input tax {{ number_format((float) $expense->tax_amount, 2) }} · @endif
            @if ($expense->paidFromAccount)Paid from {{ $expense->paidFromAccount->name }}@else Owed to <strong>{{ $payee?->name }}</strong> <span class="badge text-bg-light border">{{ $expense->payee_type }}</span> (pay it from Payments)@endif</div>
        @if ($expense->notes)<p class="mt-2 mb-1">{{ $expense->notes }}</p>@endif
        <div class="small text-muted mt-2">Entered by {{ $expense->creator?->name ?? '—' }}
            @if ($expense->journalEntry) · Journal <a href="{{ route('journal.show', $expense->journalEntry) }}">{{ $expense->journalEntry->entry_no }}</a>@endif
            @if ($expense->reversalEntry) · Reversal <a href="{{ route('journal.show', $expense->reversalEntry) }}">{{ $expense->reversalEntry->entry_no }}</a>@endif</div>
        @if ($expense->isCancelled())<div class="mt-2 text-danger small">Cancelled by {{ $expense->canceller?->name }}: {{ $expense->cancel_reason }}</div>@endif
    </div>
    <div class="col-lg-5 text-lg-end mt-3 mt-lg-0">
        @if ($expense->isDraft())
            @can('expenses.update')<a href="{{ route('expenses.edit', $expense) }}" class="btn btn-outline-secondary">Edit draft</a>@endcan
            @can('expenses.post')<form method="POST" action="{{ route('expenses.post', $expense) }}" class="d-inline">@csrf<button class="btn btn-primary">Post</button></form>@endcan
            @can('expenses.update')<form method="POST" action="{{ route('expenses.destroy', $expense) }}" class="d-inline" onsubmit="return confirm('Delete this draft?')">@csrf @method('DELETE')<button class="btn btn-outline-danger">Delete draft</button></form>@endcan
        @elseif ($expense->isPosted())
            @can('expenses.cancel')
                <form method="POST" action="{{ route('expenses.cancel', $expense) }}" onsubmit="return confirm('Cancel and reverse this expense?')">@csrf
                    <input name="reason" class="form-control mb-2" placeholder="Reason (required)" required maxlength="200"><button class="btn btn-outline-danger">Cancel &amp; reverse</button></form>
            @endcan
        @endif
    </div>
</div></div></div>
<a href="{{ route('expenses.index') }}" class="btn btn-light mt-3">Back</a>
@endsection
