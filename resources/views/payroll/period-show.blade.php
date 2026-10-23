@extends('layouts.app')
@section('title', 'Payroll ' . $period->name)
@section('content')
<div class="card mb-3"><div class="card-body d-flex flex-wrap justify-content-between gap-3 align-items-start">
    <div>
        <h5 class="mb-1">{{ $period->name }} @if ($period->run)<span class="badge text-bg-{{ ['draft' => 'warning', 'posted' => 'success', 'cancelled' => 'secondary'][$period->run->status] }}">{{ $period->run->status }}</span>@endif</h5>
        <div class="text-muted">{{ $period->start_date->format('d-M-Y') }} – {{ $period->end_date->format('d-M-Y') }} · {{ $period->working_days }} working days</div>
        @if ($period->run)<div class="small text-muted mt-2">{{ $period->run->run_no }}
            @if ($period->run->journalEntry) · Journal <a href="{{ route('journal.show', $period->run->journalEntry) }}">{{ $period->run->journalEntry->entry_no }}</a>@endif
        </div>@endif
    </div>
    <div class="d-flex gap-2">
        @if ($period->run?->isDraft())
            @can('payroll.post')<form method="POST" action="{{ route('payroll.runs.post', $period->run) }}">@csrf<button class="btn btn-primary">Post payroll</button></form>@endcan
        @elseif ($period->run?->isPosted())
            @can('payroll.cancel')
                <form method="POST" action="{{ route('payroll.runs.cancel', $period->run) }}" class="no-print" onsubmit="return confirm('Cancel and reverse this payroll run? Cancel posted salary payments first.')">
                    @csrf<input name="reason" class="form-control mb-2" placeholder="Reason (required)" required maxlength="200"><button class="btn btn-outline-danger">Cancel &amp; reverse</button>
                </form>
            @endcan
        @endif
    </div>
</div></div>

@if (! $period->run)
    @can('payroll.create')
    <form method="POST" action="{{ route('payroll.runs.store', $period) }}">
        @csrf
        <div class="alert alert-info">Enter attendance for the period. Base pay is monthly salary × (present days + paid leave days) ÷ working days. You may add an allowance and a deduction per employee; advance recovery cannot exceed the employee's outstanding advance.</div>
        <div class="card mb-3"><div class="table-responsive"><table class="table table-sm align-middle mb-0">
            <thead class="table-light"><tr><th>Include</th><th>Employee</th><th class="text-end">Monthly salary</th><th class="text-end">Present</th><th class="text-end">Paid leave</th><th class="text-end">Unpaid leave</th><th class="text-end">Advance recovery</th><th>Allowance</th><th>Deduction</th></tr></thead>
            <tbody>
            @forelse ($employees as $employee)
                <tr>
                    <td><input type="checkbox" name="employees[{{ $employee->id }}][include]" value="1" checked aria-label="Include {{ $employee->name }}"></td>
                    <td>{{ $employee->name }} <span class="text-muted small">{{ $employee->code }}</span></td>
                    <td class="text-end">{{ number_format((float) $employee->monthly_salary, 2) }}</td>
                    <td><input type="number" name="employees[{{ $employee->id }}][present_days]" class="form-control form-control-sm text-end" min="0" max="{{ $period->working_days }}" value="{{ old('employees.'.$employee->id.'.present_days', $period->working_days) }}" required></td>
                    <td><input type="number" name="employees[{{ $employee->id }}][paid_leave_days]" class="form-control form-control-sm text-end" min="0" max="{{ $period->working_days }}" value="{{ old('employees.'.$employee->id.'.paid_leave_days', 0) }}"></td>
                    <td><input type="number" name="employees[{{ $employee->id }}][unpaid_leave_days]" class="form-control form-control-sm text-end" min="0" max="{{ $period->working_days }}" value="{{ old('employees.'.$employee->id.'.unpaid_leave_days', 0) }}"></td>
                    <td><input name="employees[{{ $employee->id }}][advance_adjustment]" class="form-control form-control-sm text-end" inputmode="decimal" value="{{ old('employees.'.$employee->id.'.advance_adjustment', '0.00') }}"></td>
                    <td><div class="d-flex gap-1"><input name="employees[{{ $employee->id }}][allowances][0][description]" class="form-control form-control-sm" placeholder="Description" aria-label="Allowance description for {{ $employee->name }}"><input name="employees[{{ $employee->id }}][allowances][0][amount]" class="form-control form-control-sm text-end" inputmode="decimal" placeholder="Amount" aria-label="Allowance amount for {{ $employee->name }}"></div></td>
                    <td><div class="d-flex gap-1"><input name="employees[{{ $employee->id }}][deductions][0][description]" class="form-control form-control-sm" placeholder="Description" aria-label="Deduction description for {{ $employee->name }}"><input name="employees[{{ $employee->id }}][deductions][0][amount]" class="form-control form-control-sm text-end" inputmode="decimal" placeholder="Amount" aria-label="Deduction amount for {{ $employee->name }}"></div></td>
                </tr>
            @empty
                <tr><td colspan="9" class="text-center text-muted py-4">No active employees are available for payroll.</td></tr>
            @endforelse
            </tbody>
        </table></div></div>
        @if ($employees->isNotEmpty())<button class="btn btn-primary">Save payroll draft</button>@endif
    </form>
    @endcan
@else
    <div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
        <thead class="table-light"><tr><th>Employee</th><th class="text-end">Attendance</th><th class="text-end">Base pay</th><th class="text-end">Allowances</th><th class="text-end">Deductions</th><th class="text-end">Advance recovery</th><th class="text-end">Net payable</th><th>Actions</th></tr></thead>
        <tbody>
        @foreach ($items as $item)
            <tr>
                <td>{{ $item->employee->name }}</td>
                <td class="text-end">{{ $item->present_days }} present · {{ $item->paid_leave_days }} paid leave · {{ $item->unpaid_leave_days }} unpaid</td>
                <td class="text-end">{{ number_format((float) $item->base_pay, 2) }}</td>
                <td class="text-end">{{ number_format((float) $item->allowance_total, 2) }}</td>
                <td class="text-end">{{ number_format((float) $item->deduction_total, 2) }}</td>
                <td class="text-end">{{ number_format((float) $item->advance_adjustment, 2) }}</td>
                <td class="text-end fw-semibold">{{ number_format((float) $item->net_pay, 2) }}</td>
                <td><a href="{{ route('payroll.payslips.show', $item) }}" class="btn btn-sm btn-outline-secondary">Payslip</a></td>
            </tr>
        @endforeach
        </tbody>
        <tfoot class="table-light"><tr><th colspan="6" class="text-end">Total net payable</th><th class="text-end">{{ number_format((float) $items->sum('net_pay'), 2) }}</th><th></th></tr></tfoot>
    </table></div></div>

    @if ($period->run->isPosted())
        <div class="accordion mt-3" id="settlements">
        @foreach ($items as $item)
            @php($balanceCents = $item->outstanding_cents)
            @if ($balanceCents > 0)
            <div class="accordion-item">
                <h2 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#settlement-{{ $item->id }}">Pay {{ $item->employee->name }} · Outstanding {{ \App\Support\Money::display($balanceCents) }}</button></h2>
                <div id="settlement-{{ $item->id }}" class="accordion-collapse collapse" data-bs-parent="#settlements"><div class="accordion-body">
                    @can('payroll.settle')
                    <form method="POST" action="{{ route('payroll.payslips.settle', $item) }}" class="row g-2 align-items-end">
                        @csrf<input type="hidden" name="idempotency_key" value="{{ $settlementKeys[$item->id] }}">
                        <div class="col-md-2"><label class="form-label">Date</label><input type="date" name="payment_date" class="form-control" value="{{ now()->toDateString() }}" required></div>
                        <div class="col-md-2"><label class="form-label">Method</label><select name="method" class="form-select" required><option value="bank">Bank</option><option value="cash">Cash</option><option value="cheque">Cheque</option><option value="online">Online</option></select></div>
                        <div class="col-md-3"><label class="form-label">Account</label><select name="account_id" class="form-select" required>@foreach ($cashAccounts as $account)<option value="{{ $account->id }}">{{ $account->code }} · {{ $account->name }}</option>@endforeach</select></div>
                        <div class="col-md-2"><label class="form-label">Amount</label><input name="amount" class="form-control text-end" inputmode="decimal" value="{{ \App\Support\Money::fromCents($balanceCents) }}" required></div>
                        <div class="col-md-2"><label class="form-label">Reference</label><input name="reference" class="form-control" maxlength="60"></div>
                        <div class="col-md-1"><button class="btn btn-primary">Pay</button></div>
                    </form>
                    @endcan
                </div></div>
            </div>
            @endif
        @endforeach
        </div>
    @endif
@endif
<a href="{{ route('payroll.index') }}" class="btn btn-light mt-3">Back to payroll</a>
@endsection
