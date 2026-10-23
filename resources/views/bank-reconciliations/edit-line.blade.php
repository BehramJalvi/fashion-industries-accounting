@extends('layouts.app')
@section('title', 'Edit statement line')
@section('content')
<div class="card"><div class="card-header fw-semibold">Edit draft statement line</div><div class="card-body">
    <div class="alert alert-info">Updating a line clears its current match and rechecks for a unique exact match. Posted journal entries are never changed.</div>
    <form method="POST" action="{{ route('bank-reconciliations.lines.update', [$bankReconciliation, $line]) }}" class="row g-3">
        @csrf @method('PATCH')
        <div class="col-md-3"><label class="form-label">Date</label><input type="date" name="statement_date" value="{{ old('statement_date', $line->statement_date->toDateString()) }}" min="{{ $bankReconciliation->period_start->toDateString() }}" max="{{ $bankReconciliation->period_end->toDateString() }}" class="form-control" required></div>
        <div class="col-md-5"><label class="form-label">Description</label><input name="description" value="{{ old('description', $line->description) }}" maxlength="255" class="form-control" required></div>
        <div class="col-md-2"><label class="form-label">Reference</label><input name="reference" value="{{ old('reference', $line->reference) }}" maxlength="60" class="form-control"></div>
        <div class="col-md-2"><label class="form-label">Signed amount</label><input name="signed_amount" value="{{ old('signed_amount', $line->signed_amount) }}" inputmode="decimal" class="form-control text-end" required></div>
        <div class="col-12 d-flex gap-2"><button class="btn btn-primary">Save line</button><a href="{{ route('bank-reconciliations.show', $bankReconciliation) }}" class="btn btn-light">Cancel</a></div>
    </form>
</div></div>
@endsection
