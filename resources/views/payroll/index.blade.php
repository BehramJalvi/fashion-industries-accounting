@extends('layouts.app')
@section('title', 'Payroll')
@section('content')
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span class="fw-semibold">Salary periods</span>
        @can('payroll.create')<a href="{{ route('payroll.periods.create') }}" class="btn btn-sm btn-primary">Create salary period</a>@endcan
    </div>
    <div class="table-responsive"><table class="table table-hover align-middle mb-0">
        <thead class="table-light"><tr><th>Period</th><th>Date range</th><th class="text-end">Working days</th><th>Payroll run</th><th>Status</th></tr></thead>
        <tbody>
        @forelse ($periods as $period)
            <tr>
                <td><a href="{{ route('payroll.periods.show', $period) }}">{{ $period->name }}</a></td>
                <td>{{ $period->start_date->format('d-M-Y') }} – {{ $period->end_date->format('d-M-Y') }}</td>
                <td class="text-end">{{ $period->working_days }}</td>
                <td>{{ $period->run?->run_no ?? '—' }}</td>
                <td>
                    @if ($period->run)
                        <span class="badge text-bg-{{ ['draft' => 'warning', 'posted' => 'success', 'cancelled' => 'secondary'][$period->run->status] }}">{{ $period->run->status }}</span>
                    @else
                        <span class="badge text-bg-light">Not run</span>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="5" class="text-center text-muted py-4">No salary periods have been created.</td></tr>
        @endforelse
        </tbody>
    </table></div>
    <div class="card-footer">{{ $periods->links() }}</div>
</div>
@endsection
