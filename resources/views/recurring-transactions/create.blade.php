@extends('layouts.app')
@section('title', 'New recurring schedule')
@section('content')
<form method="POST" action="{{ route('recurring-transactions.store') }}">
    @csrf
    <div class="card" style="max-width: 900px;"><div class="card-body row g-3">
        <div class="col-md-5"><label class="form-label">Schedule name</label><input name="name" class="form-control" value="{{ old('name') }}" maxlength="120" required></div>
        <div class="col-md-3"><label class="form-label">Draft type</label>
            <select name="transaction_type" id="transaction-type" class="form-select">
                <option value="expense" @selected(old('transaction_type', 'expense') === 'expense')>Expense</option>
                <option value="fund_transfer" @selected(old('transaction_type') === 'fund_transfer')>Fund transfer</option>
            </select>
        </div>
        <div class="col-md-4"><label class="form-label">Repeat</label><select name="frequency" class="form-select" required>
            @foreach (['weekly' => 'Weekly', 'monthly' => 'Monthly', 'yearly' => 'Yearly'] as $key => $label)
                <option value="{{ $key }}" @selected(old('frequency', 'monthly') === $key)>{{ $label }}</option>
            @endforeach
        </select></div>
        <div class="col-md-4"><label class="form-label">First due date</label><input type="date" name="starts_on" class="form-control" value="{{ old('starts_on', now()->toDateString()) }}" required></div>
        <div class="col-md-4"><label class="form-label">Last due date (optional)</label><input type="date" name="ends_on" class="form-control" value="{{ old('ends_on') }}"></div>

        <fieldset id="expense-fields" class="row g-3 m-0 p-0">
            <legend class="col-12 fs-6 fw-semibold">Expense details</legend>
            <div class="col-md-6"><label class="form-label">Expense account</label><select name="expense_account_id" class="form-select">
                <option value="">Select…</option>@foreach ($expenseAccounts as $account)
                    <option value="{{ $account->id }}" @selected(old('expense_account_id') == $account->id)>{{ $account->code }} · {{ $account->name }}</option>
                @endforeach
            </select></div>
            <div class="col-md-3"><label class="form-label">Amount</label><input name="amount" class="form-control text-end" inputmode="decimal" value="{{ old('amount') }}"></div>
            <div class="col-md-3"><label class="form-label">Input tax</label><input name="tax_amount" class="form-control text-end" inputmode="decimal" value="{{ old('tax_amount', '0') }}"></div>
            <div class="col-12"><label class="form-label">Description</label><input name="description" class="form-control" value="{{ old('description') }}" maxlength="255"></div>
            <div class="col-md-6"><label class="form-label">Paid from</label><select name="paid_from_account_id" class="form-select"><option value="">—</option>
                @foreach ($cashAccounts as $account)<option value="{{ $account->id }}" @selected(old('paid_from_account_id') == $account->id)>{{ $account->code }} · {{ $account->name }}</option>@endforeach
            </select></div>
            <div class="col-md-6"><label class="form-label">Or owed to</label><select name="owed_to" class="form-select"><option value="">—</option>
                <optgroup label="Suppliers">@foreach ($suppliers as $supplier)<option value="supplier:{{ $supplier->id }}" @selected(old('owed_to') === "supplier:{$supplier->id}")>{{ $supplier->name }}</option>@endforeach</optgroup>
                <optgroup label="Employees">@foreach ($employees as $employee)<option value="employee:{{ $employee->id }}" @selected(old('owed_to') === "employee:{$employee->id}")>{{ $employee->name }}</option>@endforeach</optgroup>
            </select></div>
            <div class="col-12"><label class="form-label">Expense notes</label><input name="notes" class="form-control" value="{{ old('notes') }}" maxlength="1000"></div>
        </fieldset>

        <fieldset id="transfer-fields" class="row g-3 m-0 p-0" hidden disabled>
            <legend class="col-12 fs-6 fw-semibold">Transfer details</legend>
            <div class="col-md-6"><label class="form-label">From account</label><select name="from_account_id" class="form-select"><option value="">Select…</option>
                @foreach ($cashAccounts as $account)<option value="{{ $account->id }}" @selected(old('from_account_id') == $account->id)>{{ $account->code }} · {{ $account->name }}</option>@endforeach
            </select></div>
            <div class="col-md-6"><label class="form-label">To account</label><select name="to_account_id" class="form-select"><option value="">Select…</option>
                @foreach ($cashAccounts as $account)<option value="{{ $account->id }}" @selected(old('to_account_id') == $account->id)>{{ $account->code }} · {{ $account->name }}</option>@endforeach
            </select></div>
            <div class="col-md-6"><label class="form-label">Amount</label><input name="amount" class="form-control text-end" inputmode="decimal" value="{{ old('amount') }}"></div>
            <div class="col-md-6"><label class="form-label">Reference</label><input name="reference" class="form-control" value="{{ old('reference') }}" maxlength="60"></div>
            <div class="col-12"><label class="form-label">Transfer notes</label><input name="notes" class="form-control" value="{{ old('notes') }}" maxlength="1000"></div>
        </fieldset>
    </div></div>
    <div class="mt-3"><a href="{{ route('recurring-transactions.index') }}" class="btn btn-light">Cancel</a><button class="btn btn-primary">Create schedule</button></div>
</form>
<script>
const typeSelect = document.getElementById('transaction-type');
const expenseFields = document.getElementById('expense-fields');
const transferFields = document.getElementById('transfer-fields');
function updateRecurringFields() {
    const isExpense = typeSelect.value === 'expense';
    expenseFields.disabled = !isExpense;
    transferFields.disabled = isExpense;
    expenseFields.hidden = !isExpense;
    transferFields.hidden = isExpense;
}
typeSelect.addEventListener('change', updateRecurringFields);
updateRecurringFields();
</script>
@endsection
