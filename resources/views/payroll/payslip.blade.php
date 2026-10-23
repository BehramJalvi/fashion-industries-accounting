@extends('layouts.app')
@section('title', 'Payslip ' . $item->run->run_no)
@section('content')
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span class="fw-semibold">Payslip · {{ $item->run->period->name }}</span>
        <button type="button" class="btn btn-sm btn-outline-secondary no-print" onclick="window.print()">Print</button>
    </div>
    <div class="card-body">
        <div class="row g-3 mb-4">
            <div class="col-md-6"><div class="text-muted small">Employee</div><div class="fs-5">{{ $item->employee->name }} · {{ $item->employee->code }}</div><div>{{ $item->employee->designation }}</div></div>
            <div class="col-md-6 text-md-end"><div class="text-muted small">Payroll period</div><div>{{ $item->run->period->start_date->format('d-M-Y') }} – {{ $item->run->period->end_date->format('d-M-Y') }}</div><div>{{ ucfirst($item->run->status) }}</div></div>
        </div>
        <div class="table-responsive"><table class="table align-middle">
            <thead class="table-light"><tr><th>Pay component</th><th class="text-end">Amount (PKR)</th></tr></thead>
            <tbody>
                <tr><td>Monthly salary ({{ $item->present_days }} present + {{ $item->paid_leave_days }} paid leave / {{ $item->run->period->working_days }} working days)</td><td class="text-end">{{ number_format((float) $item->base_pay, 2) }}</td></tr>
                @foreach ($item->components as $component)
                    <tr><td>{{ ucfirst($component->component_type) }} · {{ $component->description }}</td><td class="text-end">{{ $component->component_type === 'deduction' ? '−' : '' }}{{ number_format((float) $component->amount, 2) }}</td></tr>
                @endforeach
                @if ((float) $item->advance_adjustment > 0)<tr><td>Employee advance recovery</td><td class="text-end">−{{ number_format((float) $item->advance_adjustment, 2) }}</td></tr>@endif
            </tbody>
            <tfoot class="table-light"><tr class="fw-bold"><td>Net salary payable</td><td class="text-end">{{ number_format((float) $item->net_pay, 2) }}</td></tr></tfoot>
        </table></div>
        <div class="small text-muted">Attendance: {{ $item->present_days }} present, {{ $item->paid_leave_days }} paid leave, {{ $item->unpaid_leave_days }} unpaid leave.</div>
        @if ($item->settlements->isNotEmpty())
            <hr><h6>Payments</h6>
            @foreach ($item->settlements as $settlement)
                <div>{{ $settlement->payment->payment_no }} · {{ $settlement->payment->payment_date->format('d-M-Y') }} · {{ number_format((float) $settlement->amount, 2) }} · {{ $settlement->payment->status }}</div>
            @endforeach
            <div class="fw-semibold mt-2">Outstanding: {{ \App\Support\Money::display($item->outstanding_cents) }}</div>
        @endif
    </div>
</div>
<a href="{{ route('payroll.periods.show', $item->run->period) }}" class="btn btn-light mt-3 no-print">Back</a>
@endsection
