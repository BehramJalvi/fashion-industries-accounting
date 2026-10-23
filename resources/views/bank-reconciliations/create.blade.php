@extends('layouts.app')
@section('title', 'Start bank reconciliation')
@section('content')
<div class="alert alert-info">Statement CSV files are used only in this bank reconciliation workflow. Use headers <code>date,description,reference,amount</code> with dates in YYYY-MM-DD format and signed amounts (deposits positive, withdrawals negative). Preview is required before saving.</div>
<div class="card mb-3"><div class="card-header fw-semibold">Preview and import a CSV statement</div><div class="card-body">
    <form method="POST" action="{{ route('bank-reconciliations.preview') }}" enctype="multipart/form-data" class="row g-3">
        @csrf
        <div class="col-md-4"><label class="form-label">Bank account</label><select name="account_id" class="form-select" required><option value="">Select…</option>
            @foreach ($accounts as $account)<option value="{{ $account->id }}" @selected(old('account_id') == $account->id)>{{ $account->code }} · {{ $account->name }}</option>@endforeach
        </select></div>
        <div class="col-md-4"><label class="form-label">Period from</label><input type="date" name="period_start" class="form-control" value="{{ old('period_start') }}" required></div>
        <div class="col-md-4"><label class="form-label">Period to</label><input type="date" name="period_end" class="form-control" value="{{ old('period_end') }}" required></div>
        <div class="col-md-4"><label class="form-label">Opening balance</label><input name="opening_balance" class="form-control text-end" inputmode="decimal" value="{{ old('opening_balance', '0.00') }}" required></div>
        <div class="col-md-4"><label class="form-label">Closing balance</label><input name="closing_balance" class="form-control text-end" inputmode="decimal" value="{{ old('closing_balance') }}" required></div>
        <div class="col-md-8"><label class="form-label">CSV statement file</label><input type="file" name="statement_file" class="form-control" accept=".csv,.txt,text/csv" required></div>
        <div class="col-12"><button class="btn btn-primary">Preview statement</button></div>
    </form>
</div></div>

<div class="card"><div class="card-header fw-semibold">Start a manual statement</div><div class="card-body">
    <form method="POST" action="{{ route('bank-reconciliations.store') }}" class="row g-3">
        @csrf
        <div class="col-md-4"><label class="form-label">Bank account</label><select name="account_id" class="form-select" required><option value="">Select…</option>
            @foreach ($accounts as $account)<option value="{{ $account->id }}">{{ $account->code }} · {{ $account->name }}</option>@endforeach
        </select></div>
        <div class="col-md-4"><label class="form-label">Period from</label><input type="date" name="period_start" class="form-control" required></div>
        <div class="col-md-4"><label class="form-label">Period to</label><input type="date" name="period_end" class="form-control" required></div>
        <div class="col-md-4"><label class="form-label">Opening balance</label><input name="opening_balance" class="form-control text-end" inputmode="decimal" value="0.00" required></div>
        <div class="col-md-4"><label class="form-label">Closing balance</label><input name="closing_balance" class="form-control text-end" inputmode="decimal" required></div>
        <div class="col-12"><button class="btn btn-outline-primary">Start manual reconciliation</button></div>
    </form>
</div></div>
<a href="{{ route('bank-reconciliations.index') }}" class="btn btn-light mt-3">Back</a>
@endsection
