@extends('layouts.app')
@section('title', $transfer->exists ? 'Edit draft fund transfer' : 'New fund transfer')
@section('content')
<form method="POST" action="{{ $transfer->exists ? route('fund-transfers.update', $transfer) : route('fund-transfers.store') }}">
    @csrf
    @if ($transfer->exists) @method('PUT') @endif
    <div class="card" style="max-width: 760px;"><div class="card-body row g-3">
        <div class="col-md-4"><label class="form-label">Date</label><input type="date" name="transfer_date" class="form-control" value="{{ old('transfer_date', $transfer->transfer_date?->toDateString() ?? now()->toDateString()) }}" required></div>
        <div class="col-md-4"><label class="form-label">From</label>
            <select name="from_account_id" class="form-select" required>@foreach ($accounts as $a)<option value="{{ $a->id }}" @selected(old('from_account_id', $transfer->from_account_id) == $a->id)>{{ $a->code }} · {{ $a->name }}</option>@endforeach</select></div>
        <div class="col-md-4"><label class="form-label">To</label>
            <select name="to_account_id" class="form-select" required>@foreach ($accounts as $a)<option value="{{ $a->id }}" @selected(old('to_account_id', $transfer->to_account_id ?? ($accounts->count() > 1 ? $accounts[1]->id : null)) == $a->id)>{{ $a->code }} · {{ $a->name }}</option>@endforeach</select></div>
        <div class="col-md-4"><label class="form-label">Amount (PKR)</label><input name="amount" class="form-control text-end" inputmode="decimal" value="{{ old('amount', $transfer->amount) }}" required></div>
        <div class="col-md-8"><label class="form-label">Cheque no. / reference</label><input name="reference" class="form-control" value="{{ old('reference', $transfer->reference) }}" maxlength="60"></div>
        <div class="col-12"><label class="form-label">Notes</label><input name="notes" class="form-control" value="{{ old('notes', $transfer->notes) }}" maxlength="1000" placeholder="e.g. Cheque cashed by Mohsin"></div>
        <div class="col-12 small text-muted">Moves money between your own cash and bank accounts. It is not an expense or income.</div>
    </div></div>
    <div class="mt-3">
        <a href="{{ $transfer->exists ? route('fund-transfers.show', $transfer) : route('fund-transfers.index') }}" class="btn btn-light">Cancel</a>
        <button name="action" value="draft" class="btn btn-outline-primary">Save as draft</button>
        @can('fund_transfers.post')<button name="action" value="post" class="btn btn-primary">Save &amp; post</button>@endcan
    </div>
</form>
@endsection
