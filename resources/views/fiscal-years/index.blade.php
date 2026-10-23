@extends('layouts.app')
@section('title', 'Fiscal Year Closing')
@section('content')
<div class="card">
    <div class="card-header fw-semibold">Fiscal years</div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr><th>Fiscal year</th><th>Date range</th><th>Status</th><th>Close history</th><th class="text-end">Actions</th></tr>
            </thead>
            <tbody>
            @forelse ($fiscalYears as $fiscalYear)
                <tr>
                    <td>{{ $fiscalYear->name }}</td>
                    <td>{{ $fiscalYear->start_date->format('d-M-Y') }} – {{ $fiscalYear->end_date->format('d-M-Y') }}</td>
                    <td>
                        <span class="badge text-bg-{{ $fiscalYear->is_closed ? 'secondary' : 'success' }}">
                            {{ $fiscalYear->is_closed ? 'Closed' : 'Open' }}
                        </span>
                    </td>
                    <td>
                        @forelse ($fiscalYear->closeEvents as $event)
                            <div class="small">
                                Cycle {{ $event->cycle }} {{ ucfirst($event->event_type) }}
                                @if ($event->journalEntry)
                                    · <a href="{{ route('journal.show', $event->journalEntry) }}">{{ $event->journalEntry->entry_no }}</a>
                                @endif
                                @if ($event->reversalEntry)
                                    · reversal <a href="{{ route('journal.show', $event->reversalEntry) }}">{{ $event->reversalEntry->entry_no }}</a>
                                @endif
                                @if ($event->reason)
                                    · {{ $event->reason }}
                                @endif
                                <span class="text-muted">({{ $event->creator?->name ?? 'System' }}, {{ $event->created_at->format('d-M-Y H:i') }})</span>
                            </div>
                        @empty
                            <span class="text-muted">No close history</span>
                        @endforelse
                    </td>
                    <td class="text-end text-nowrap">
                        @if (! $fiscalYear->is_closed)
                            @can('fiscal_years.close')
                                <form method="POST" action="{{ route('fiscal-years.close', $fiscalYear) }}" class="d-inline">
                                    @csrf
                                    <button class="btn btn-sm btn-outline-primary" onclick="return confirm('Close this fiscal year? Its revenue and expense balances will be transferred to Retained Earnings.')">Close year</button>
                                </form>
                            @endcan
                        @else
                            @can('fiscal_years.reopen')
                                <form method="POST" action="{{ route('fiscal-years.reopen', $fiscalYear) }}" class="d-flex gap-2 justify-content-end">
                                    @csrf
                                    <input class="form-control form-control-sm" name="reason" required maxlength="255" placeholder="Reason for reopening" aria-label="Reason for reopening">
                                    <button class="btn btn-sm btn-outline-danger text-nowrap" onclick="return confirm('Reopen this fiscal year? The closing journal will be reversed.')">Reopen year</button>
                                </form>
                            @endcan
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="text-center text-muted py-4">No fiscal years are configured.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="card-footer">{{ $fiscalYears->links() }}</div>
</div>
@endsection
