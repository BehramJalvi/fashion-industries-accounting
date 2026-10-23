@extends('layouts.app')
@section('title', $expense->exists ? 'Edit draft expense' : 'New expense')
@section('content')
<form method="POST" action="{{ $expense->exists ? route('expenses.update', $expense) : route('expenses.store') }}">
    @csrf
    @if ($expense->exists) @method('PUT') @endif
    <div class="card" style="max-width: 860px;"><div class="card-body row g-3">
        <div class="col-md-3"><label class="form-label">Date</label><input type="date" name="expense_date" class="form-control" value="{{ old('expense_date', $expense->expense_date?->toDateString() ?? now()->toDateString()) }}" required></div>
        <div class="col-md-5"><label class="form-label">Expense type</label>
            <select name="expense_account_id" class="form-select" required><option value="">Select…</option>@foreach ($accounts as $a)<option value="{{ $a->id }}" @selected(old('expense_account_id', $expense->expense_account_id) == $a->id)>{{ $a->code }} · {{ $a->name }}</option>@endforeach</select></div>
        <div class="col-md-2"><label class="form-label">Amount</label><input name="amount" class="form-control text-end" inputmode="decimal" value="{{ old('amount', $expense->amount) }}" required></div>
        <div class="col-md-2"><label class="form-label">Input tax</label><input name="tax_amount" class="form-control text-end" inputmode="decimal" value="{{ old('tax_amount', $expense->tax_amount ?? '0.00') }}" placeholder="0"></div>
        <div class="col-12"><label class="form-label">Description</label><input name="description" class="form-control" value="{{ old('description', $expense->description) }}" required maxlength="255" placeholder="e.g. Dyeing charges for October, Factory rent, Mohsin salary"></div>

        <div class="col-12"><hr class="my-1"><div class="fw-semibold mb-1">How was it paid? <small class="text-muted">(choose one)</small></div></div>
        <div class="col-md-6"><label class="form-label">Paid now from</label>
            <select name="paid_from_account_id" class="form-select"><option value="">—</option>@foreach ($cashAccounts as $a)<option value="{{ $a->id }}" @selected(old('paid_from_account_id', $expense->paid_from_account_id) == $a->id)>{{ $a->code }} · {{ $a->name }}</option>@endforeach</select></div>
        <div class="col-md-6"><label class="form-label">…or owed to (pay later)</label>
            <select name="owed_to" class="form-select"><option value="">—</option>
                <optgroup label="Suppliers">@foreach ($suppliers as $s)<option value="supplier:{{ $s->id }}" @selected(old('owed_to', $expense->payee_type === 'supplier' ? "supplier:{$expense->payee_id}" : null) === "supplier:{$s->id}")>{{ $s->name }}</option>@endforeach</optgroup>
                <optgroup label="Employees">@foreach ($employees as $e)<option value="employee:{{ $e->id }}" @selected(old('owed_to', $expense->payee_type === 'employee' ? "employee:{$expense->payee_id}" : null) === "employee:{$e->id}")>{{ $e->name }}</option>@endforeach</optgroup></select></div>
        <div class="col-12"><label class="form-label">Notes</label><input name="notes" class="form-control" value="{{ old('notes', $expense->notes) }}" maxlength="1000"></div>
        <div class="col-12 small text-muted">An expense owed to a supplier or employee (a salary, a processing bill, money an employee spent for you) is paid later from Payments.</div>
    </div></div>
    <div class="mt-3">
        <a href="{{ $expense->exists ? route('expenses.show', $expense) : route('expenses.index') }}" class="btn btn-light">Cancel</a>
        <button name="action" value="draft" class="btn btn-outline-primary">Save as draft</button>
        @can('expenses.post')<button name="action" value="post" class="btn btn-primary">Save &amp; post</button>@endcan
    </div>
</form>
@endsection
