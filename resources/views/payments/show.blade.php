@extends('layouts.app')
@section('title', $payment->payment_no)
@section('content')
@php
    $m = fn ($c) => number_format($c / 100, 2);
@endphp
<div class="card mb-3"><div class="card-body"><div class="row">
    <div class="col-lg-7">
        <h5>{{ $payment->payment_no }} <span class="badge text-bg-{{ ['draft' => 'warning', 'posted' => 'success', 'cancelled' => 'secondary'][$payment->status] }}">{{ $payment->status }}</span></h5>
        <div class="fs-4">PKR {{ number_format((float) $payment->amount, 2) }}</div>
        <div>To <a href="{{ route($payment->payee_type . 's.ledger', $payment->payee_id) }}">{{ $payee?->name ?? 'unknown' }}</a> <span class="badge text-bg-light border">{{ $payment->payee_type }}</span> · {{ $payment->payment_date->format('d-M-Y') }}</div>
        <div class="text-muted">{{ ucfirst($payment->method) }} from {{ $payment->account->name }}@if ($payment->reference) · Ref {{ $payment->reference }}@endif</div>
        @if ($payment->notes)<p class="mt-2 mb-1">{{ $payment->notes }}</p>@endif
        <div class="small text-muted mt-2">Entered by {{ $payment->creator?->name ?? '—' }}
            @if ($payment->journalEntry) · Journal <a href="{{ route('journal.show', $payment->journalEntry) }}">{{ $payment->journalEntry->entry_no }}</a>@endif
            @if ($payment->reversalEntry) · Reversal <a href="{{ route('journal.show', $payment->reversalEntry) }}">{{ $payment->reversalEntry->entry_no }}</a>@endif</div>
        @if ($payment->isCancelled())<div class="mt-2 text-danger small">Cancelled by {{ $payment->canceller?->name }}: {{ $payment->cancel_reason }}</div>@endif
    </div>
    <div class="col-lg-5 text-lg-end mt-3 mt-lg-0">
        @if ($payment->isDraft())
            @can('payments.post')<form method="POST" action="{{ route('payments.post', $payment) }}" class="d-inline">@csrf<button class="btn btn-primary">Post</button></form>@endcan
            @can('payments.update')<form method="POST" action="{{ route('payments.destroy', $payment) }}" class="d-inline" onsubmit="return confirm('Delete this draft?')">@csrf @method('DELETE')<button class="btn btn-outline-danger">Delete draft</button></form>@endcan
        @elseif ($payment->isPosted())
            @can('payments.cancel')
                <form method="POST" action="{{ route('payments.cancel', $payment) }}" onsubmit="return confirm('Cancel this payment? The bills it paid become unpaid again.')">@csrf
                    <input name="reason" class="form-control mb-2" placeholder="Reason (required)" required maxlength="200"><button class="btn btn-outline-danger">Cancel &amp; reverse</button></form>
            @endcan
        @endif
    </div>
</div></div></div>

<div class="card mb-3"><div class="card-header fw-semibold">Applied to bills</div>
    @if ($allocations->isEmpty())<div class="card-body text-muted">Not applied to any bill{{ $payment->isPosted() ? ': it reduces the balance on account.' : '.' }}</div>
    @else
    <table class="table table-sm align-middle mb-0"><thead class="table-light"><tr><th>Bill</th><th>Date</th><th class="text-end">Amount applied</th><th></th></tr></thead><tbody>
    @foreach ($allocations as $a)
        @php
            $bill = $bills[$a->target_id] ?? null;
        @endphp
        <tr class="{{ $a->is_active ? '' : 'text-muted' }}"><td>@if ($bill)<a href="{{ route('purchases.show', $bill) }}">{{ $bill->purchase_no }}</a>{{ $bill->supplier_ref ? ' (' . $bill->supplier_ref . ')' : '' }}@endif</td><td>{{ $bill?->purchase_date->format('d-M-Y') }}</td>
            <td class="text-end">{{ number_format((float) $a->amount, 2) }}</td><td>{{ $a->is_active ? '' : ($payment->isDraft() ? 'pending post' : 'released') }}</td></tr>
    @endforeach</tbody></table>
    @endif
    @if ($payment->isPosted())<div class="card-footer d-flex justify-content-between"><span>On account (not applied to a bill)</span><strong>{{ $m($unallocated) }}</strong></div>@endif
</div>

@if ($payment->isPosted() && $unallocated > 0 && $open->isNotEmpty())
    @can('payments.post')
    <form method="POST" action="{{ route('payments.allocate', $payment) }}">@csrf
        <h6>Apply the {{ $m($unallocated) }} on account to open bills</h6>
        @include('settlements._open-items', ['open' => $open, 'kind' => 'payment', 'fillCents' => $unallocated])
        <button class="btn btn-primary">Apply</button>
    </form>
    @endcan
@endif
<a href="{{ route('payments.index') }}" class="btn btn-light mt-3">Back</a>
@endsection
