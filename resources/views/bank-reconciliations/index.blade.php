@extends('layouts.app')
@section('title', 'Bank Reconciliation')
@section('content')
<div class="card">
    <div class="card-header d-flex flex-wrap gap-2 justify-content-between">
        <form method="GET" class="d-flex gap-2">
            <select name="status" class="form-select form-select-sm">
                <option value="">Any status</option>
                @foreach (['draft', 'reconciled'] as $status)<option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>@endforeach
            </select>
            <button class="btn btn-sm btn-outline-secondary">Filter</button>
        </form>
        @can('bank_reconciliations.create')<a href="{{ route('bank-reconciliations.create') }}" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg"></i> New reconciliation</a>@endcan
    </div>
    <div class="table-responsive"><table class="table table-hover align-middle mb-0">
        <thead class="table-light"><tr><th>Bank account</th><th>Statement period</th><th>Closing balance</th><th>Source</th><th>Status</th></tr></thead>
        <tbody>
        @forelse ($reconciliations as $reconciliation)
            <tr>
                <td>{{ $reconciliation->account->code }} · {{ $reconciliation->account->name }}</td>
                <td><a href="{{ route('bank-reconciliations.show', $reconciliation) }}">{{ $reconciliation->period_start->format('d-M-Y') }} – {{ $reconciliation->period_end->format('d-M-Y') }}</a></td>
                <td class="text-end">{{ number_format((float) $reconciliation->closing_balance, 2) }}</td>
                <td>{{ strtoupper($reconciliation->source) }}</td>
                <td><span class="badge text-bg-{{ $reconciliation->status === 'reconciled' ? 'success' : 'warning' }}">{{ ucfirst($reconciliation->status) }}</span></td>
            </tr>
        @empty
            <tr><td colspan="5" class="text-center text-muted py-4">No bank statements have been reconciled.</td></tr>
        @endforelse
        </tbody>
    </table></div>
    <div class="card-footer">{{ $reconciliations->links() }}</div>
</div>
@endsection
