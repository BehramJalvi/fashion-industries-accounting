@extends('layouts.app')
@section('title', 'New owner capital / drawing')
@section('content')
<form method="POST" action="{{ route('owner-transactions.store') }}">
    @csrf
    <div class="card" style="max-width: 760px;"><div class="card-body row g-3">
        <div class="col-md-6"><label class="form-label">Owner</label>
            <select name="owner_id" class="form-select" required>@foreach ($owners as $o)<option value="{{ $o->id }}" @selected(old('owner_id') == $o->id)>{{ $o->name }}</option>@endforeach</select></div>
        <div class="col-md-6"><label class="form-label">Type</label>
            <select name="txn_type" class="form-select">
                <option value="capital" @selected(old('txn_type', 'capital') === 'capital')>Capital introduced (owner puts money in)</option>
                <option value="drawing" @selected(old('txn_type') === 'drawing')>Drawings (owner takes money out)</option></select></div>
        <div class="col-md-4"><label class="form-label">Date</label><input type="date" name="txn_date" class="form-control" value="{{ old('txn_date', now()->toDateString()) }}" required></div>
        <div class="col-md-4"><label class="form-label">Cash / Bank account</label>
            <select name="account_id" class="form-select" required>@foreach ($accounts as $a)<option value="{{ $a->id }}" @selected(old('account_id') == $a->id)>{{ $a->code }} · {{ $a->name }}</option>@endforeach</select></div>
        <div class="col-md-4"><label class="form-label">Amount (PKR)</label><input name="amount" class="form-control text-end" inputmode="decimal" value="{{ old('amount') }}" required></div>
        <div class="col-12"><label class="form-label">Notes</label><input name="notes" class="form-control" value="{{ old('notes') }}" maxlength="1000"></div>
        <div class="col-12 small text-muted">Capital: Dr Cash/Bank, Cr Owner Capital. Drawings: Dr Owner Drawings, Cr Cash/Bank.</div>
    </div></div>
    <div class="mt-3">
        <a href="{{ route('owner-transactions.index') }}" class="btn btn-light">Cancel</a>
        <button name="action" value="draft" class="btn btn-outline-primary">Save as draft</button>
        @can('owner_transactions.post')<button name="action" value="post" class="btn btn-primary">Save &amp; post</button>@endcan
    </div>
</form>
@endsection
