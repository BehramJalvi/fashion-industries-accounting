@extends('layouts.app')
@section('title', 'Recurring Transactions')
@section('content')
<div class="card">
    <div class="card-header d-flex flex-wrap gap-2 justify-content-between align-items-center">
        <span class="text-muted">Due schedules create drafts only; review and post each document from its transaction screen.</span>
        <div class="d-flex gap-2">
            @can('recurring_transactions.run')
                <form method="POST" action="{{ route('recurring-transactions.run-due') }}">@csrf<button class="btn btn-sm btn-outline-primary">Generate due drafts</button></form>
            @endcan
            @can('recurring_transactions.create')
                <a href="{{ route('recurring-transactions.create') }}" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg"></i> New schedule</a>
            @endcan
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light"><tr><th>Schedule</th><th>Document type</th><th>Frequency</th><th>Next due</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @forelse ($templates as $template)
                <tr>
                    <td><a href="{{ route('recurring-transactions.show', $template) }}">{{ $template->name }}</a></td>
                    <td>{{ str($template->transaction_type)->replace('_', ' ')->title() }}</td>
                    <td>{{ ucfirst($template->frequency) }}</td>
                    <td>{{ $template->next_run_on->format('d-M-Y') }}@if ($template->ends_on) <span class="text-muted">through {{ $template->ends_on->format('d-M-Y') }}</span>@endif</td>
                    <td><span class="badge text-bg-{{ $template->is_active ? 'success' : 'secondary' }}">{{ $template->is_active ? 'Active' : 'Paused' }}</span></td>
                    <td class="text-end">
                        @can('recurring_transactions.update')
                            <form method="POST" action="{{ route('recurring-transactions.toggle', $template) }}">@csrf @method('PATCH')
                                <button class="btn btn-sm btn-outline-secondary">{{ $template->is_active ? 'Pause' : 'Resume' }}</button>
                            </form>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-center text-muted py-4">No recurring schedules have been created.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="card-footer">{{ $templates->links() }}</div>
</div>
@endsection
