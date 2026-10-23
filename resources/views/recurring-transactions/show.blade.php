@extends('layouts.app')
@section('title', $template->name)
@section('content')
<div class="card mb-3">
    <div class="card-body d-flex flex-wrap justify-content-between gap-3">
        <div>
            <h5>{{ $template->name }} <span class="badge text-bg-{{ $template->is_active ? 'success' : 'secondary' }}">{{ $template->is_active ? 'Active' : 'Paused' }}</span></h5>
            <div>{{ str($template->transaction_type)->replace('_', ' ')->title() }} · {{ ucfirst($template->frequency) }} · next {{ $template->next_run_on->format('d-M-Y') }}</div>
            <div class="small text-muted">Created by {{ $template->creator?->name ?? 'Scheduler / system' }}
                @if ($template->ends_on) · Ends {{ $template->ends_on->format('d-M-Y') }}@endif
            </div>
        </div>
        @can('recurring_transactions.update')
            <form method="POST" action="{{ route('recurring-transactions.toggle', $template) }}">@csrf @method('PATCH')
                <button class="btn btn-outline-secondary">{{ $template->is_active ? 'Pause schedule' : 'Resume schedule' }}</button>
            </form>
        @endcan
    </div>
</div>
<div class="card">
    <div class="card-header fw-semibold">Execution history <span class="text-muted fw-normal">· generated documents remain drafts until reviewed and posted</span></div>
    <div class="table-responsive"><table class="table table-hover align-middle mb-0">
        <thead class="table-light"><tr><th>Scheduled for</th><th>Generated</th><th>Document</th><th>Status</th></tr></thead>
        <tbody>
        @forelse ($executions as $execution)
            <tr>
                <td>{{ $execution->scheduled_for->format('d-M-Y') }}</td>
                <td>{{ $execution->generated_at->format('d-M-Y H:i') }}</td>
                <td>
                    @if ($execution->document_type === 'expense' && auth()->user()->can('expenses.view'))
                        <a href="{{ route('expenses.show', $execution->document_id) }}">Expense {{ $execution->document?->expense_no ?? '#'.$execution->document_id }}</a>
                    @elseif ($execution->document_type === 'fund_transfer' && auth()->user()->can('fund_transfers.view'))
                        <a href="{{ route('fund-transfers.show', $execution->document_id) }}">Transfer {{ $execution->document?->transfer_no ?? '#'.$execution->document_id }}</a>
                    @else
                        {{ str($execution->document_type)->replace('_', ' ')->title() }} #{{ $execution->document_id }}
                    @endif
                </td>
                <td><span class="badge text-bg-{{ $execution->status === 'draft' ? 'warning' : 'secondary' }}">{{ $execution->status }}</span></td>
            </tr>
        @empty
            <tr><td colspan="4" class="text-center text-muted py-4">No occurrences have been generated.</td></tr>
        @endforelse
        </tbody>
    </table></div>
    <div class="card-footer">{{ $executions->links() }}</div>
</div>
<a href="{{ route('recurring-transactions.index') }}" class="btn btn-light mt-3">Back to schedules</a>
@endsection
