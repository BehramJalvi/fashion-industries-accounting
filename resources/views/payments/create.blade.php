@extends('layouts.app')
@section('title', 'Payment to ' . $payee->name)
@section('content')
<form method="POST" action="{{ route('payments.store') }}">
    @csrf
    <input type="hidden" name="payee_type" value="{{ $type }}"><input type="hidden" name="payee_id" value="{{ $payee->id }}">
    <div class="card mb-3"><div class="card-body row g-3">
        <div class="col-12"><span class="fs-5">{{ $payee->name }}</span> <span class="badge text-bg-light border">{{ $type }}</span>
            <span class="ms-2 text-muted">{{ (float) $owed >= 0 ? 'we owe ' . number_format((float) $owed, 2) : 'has been overpaid by ' . number_format(abs((float) $owed), 2) . ' (owes us)' }}</span></div>
        <div class="col-md-3"><label class="form-label">Date</label><input type="date" name="payment_date" class="form-control" value="{{ old('payment_date', now()->toDateString()) }}" required></div>
        <div class="col-md-3"><label class="form-label">Method</label>
            <select name="method" class="form-select">@foreach (['cash' => 'Cash', 'bank' => 'Bank transfer', 'cheque' => 'Cheque', 'online' => 'Online'] as $v => $l)<option value="{{ $v }}" @selected(old('method', 'cash') === $v)>{{ $l }}</option>@endforeach</select></div>
        <div class="col-md-3"><label class="form-label">Paid from</label>
            <select name="account_id" class="form-select" required>@foreach ($accounts as $a)<option value="{{ $a->id }}" @selected(old('account_id') == $a->id)>{{ $a->code }} · {{ $a->name }}</option>@endforeach</select></div>
        <div class="col-md-3"><label class="form-label">Amount (PKR)</label><input name="amount" id="amount" class="form-control text-end" inputmode="decimal" value="{{ old('amount') }}" required></div>
        <div class="col-md-4"><label class="form-label">Cheque / transaction ref.</label><input name="reference" class="form-control" value="{{ old('reference') }}" maxlength="60"></div>
        <div class="col-md-8"><label class="form-label">Notes</label><input name="notes" class="form-control" value="{{ old('notes') }}" maxlength="1000"></div>
    </div></div>

    @include('settlements._open-items', ['open' => $open, 'kind' => 'payment'])

    @if ($open->isNotEmpty())
        <div class="form-check mb-3"><input type="hidden" name="auto_allocate" value="0"><input class="form-check-input" type="checkbox" name="auto_allocate" value="1" id="auto" @checked(old('auto_allocate'))>
            <label class="form-check-label" for="auto">Ignore the boxes above and apply the amount to the oldest bills first</label></div>
    @endif
    <p class="small text-muted">Whatever is not applied to a bill (salary, processing charges, an advance) simply reduces {{ $payee->name }}'s balance.</p>

    <a href="{{ route('payments.index') }}" class="btn btn-light">Cancel</a>
    <button name="action" value="draft" class="btn btn-outline-primary">Save as draft</button>
    @can('payments.post')<button name="action" value="post" class="btn btn-primary">Save &amp; post</button>@endcan
</form>
@endsection
