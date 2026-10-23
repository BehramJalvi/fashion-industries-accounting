@extends('layouts.app')
@section('title', 'Bank reconciliation')
@section('content')
<div class="card mb-3"><div class="card-body d-flex flex-wrap justify-content-between gap-3">
    <div>
        <h5>{{ $reconciliation->account->code }} · {{ $reconciliation->account->name }}
            <span class="badge text-bg-{{ $reconciliation->status === 'reconciled' ? 'success' : 'warning' }}">{{ ucfirst($reconciliation->status) }}</span>
        </h5>
        <div>Statement period: {{ $reconciliation->period_start->format('d-M-Y') }} – {{ $reconciliation->period_end->format('d-M-Y') }} · {{ strtoupper($reconciliation->source) }}</div>
        <div class="small text-muted">Opening {{ number_format((float) $reconciliation->opening_balance, 2) }} · Closing {{ number_format((float) $reconciliation->closing_balance, 2) }}
            @if ($reconciliation->reconciled_at) · Reconciled by {{ $reconciliation->reconciler?->name ?? '—' }} on {{ $reconciliation->reconciled_at->format('d-M-Y H:i') }}@endif
        </div>
    </div>
    @if ($reconciliation->status === 'draft')
        <div class="d-flex gap-2 align-items-start">
            @can('bank_reconciliations.update')
                <form method="POST" action="{{ route('bank-reconciliations.auto-match', $reconciliation) }}">@csrf<button class="btn btn-outline-primary">Auto-match exact items</button></form>
            @endcan
            @can('bank_reconciliations.reconcile')
                <form method="POST" action="{{ route('bank-reconciliations.reconcile', $reconciliation) }}">@csrf<button class="btn btn-success">Reconcile and lock</button></form>
            @endcan
        </div>
    @endif
</div></div>

<div class="row g-3 mb-3">
    <div class="col-md-3"><div class="card card-body"><div class="small text-muted">Statement lines</div><strong>{{ $summary['line_count'] }}</strong></div></div>
    <div class="col-md-3"><div class="card card-body"><div class="small text-muted">Matched / unmatched statement lines</div><strong>{{ $summary['matched_statement_count'] }} / {{ $summary['unmatched_statement_count'] }}</strong></div></div>
    <div class="col-md-3"><div class="card card-body"><div class="small text-muted">Statement balance difference</div><strong class="{{ $summary['difference_cents'] === 0 ? 'text-success' : 'text-danger' }}">{{ \App\Support\Money::display($summary['difference_cents']) }}</strong></div></div>
    <div class="col-md-3"><div class="card card-body"><div class="small text-muted">Unmatched book items / net</div><strong>{{ $summary['unmatched_book_count'] }} / {{ \App\Support\Money::display($summary['unmatched_book_cents']) }}</strong></div></div>
</div>

<div class="card mb-3">
    <div class="card-header fw-semibold">Statement lines</div>
    <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
        <thead class="table-light"><tr><th>Date</th><th>Description / reference</th><th class="text-end">Amount</th><th>Match status</th><th>Actions / manual match</th></tr></thead>
        <tbody>
        @forelse ($lines as $line)
            <tr>
                <td>{{ $line->statement_date->format('d-M-Y') }}</td>
                <td>{{ $line->description }} @if ($line->reference)<div class="small text-muted">Ref {{ $line->reference }}</div>@endif</td>
                <td class="text-end">{{ number_format((float) $line->signed_amount, 2) }}</td>
                <td>
                    @if ($line->matchedJournalLine)
                        <span class="badge text-bg-success">Matched</span>
                        <div class="small"><a href="{{ route('journal.show', $line->matchedJournalLine->entry) }}">{{ $line->matchedJournalLine->entry->entry_no }}</a> · line {{ $line->matchedJournalLine->line_no }}</div>
                    @else
                        <span class="badge text-bg-warning">Unmatched</span>
                    @endif
                </td>
                <td>
                    @if ($reconciliation->status === 'draft' && ! $line->matched_journal_entry_line_id && $candidates->has($line->id))
                        <form method="POST" action="{{ route('bank-reconciliations.lines.match', [$reconciliation, $line]) }}" class="d-flex gap-1">
                            @csrf
                            <select name="journal_entry_line_id" class="form-select form-select-sm" required>
                                <option value="">Select posted transaction…</option>
                                @foreach ($candidates->get($line->id) as $candidate)
                                    <option value="{{ $candidate->id }}">{{ $candidate->entry->entry_date->format('d-M') }} · {{ $candidate->entry->entry_no }} · {{ $candidate->entry->description }} · {{ number_format((float) $candidate->debit - (float) $candidate->credit, 2) }}</option>
                                @endforeach
                            </select>
                            @can('bank_reconciliations.update')<button class="btn btn-sm btn-outline-primary">Match</button>@endcan
                        </form>
                    @elseif (! $line->matched_journal_entry_line_id)
                        <span class="small text-muted">No exact-amount candidate in this period.</span>
                    @endif
                    @if ($reconciliation->status === 'draft')
                        <div class="d-flex gap-1 mt-1">
                            @can('bank_reconciliations.update')
                                <a href="{{ route('bank-reconciliations.lines.edit', [$reconciliation, $line]) }}" class="btn btn-sm btn-outline-secondary">Edit</a>
                                <form method="POST" action="{{ route('bank-reconciliations.lines.destroy', [$reconciliation, $line]) }}" onsubmit="return confirm('Remove this draft statement line?')">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger">Remove</button></form>
                            @endcan
                        </div>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="5" class="text-center text-muted py-4">No statement lines recorded.</td></tr>
        @endforelse
        </tbody>
    </table></div>
    <div class="card-footer">{{ $lines->links() }}</div>
</div>

<div class="card mb-3">
    <div class="card-header fw-semibold">Unmatched posted bank transactions <span class="small fw-normal text-muted">· at most 100 shown</span></div>
    <div class="table-responsive"><table class="table table-sm mb-0">
        <thead class="table-light"><tr><th>Date</th><th>Journal</th><th>Description / reference</th><th class="text-end">Bank movement</th></tr></thead>
        <tbody>@forelse ($unmatchedBookItems as $item)
            <tr><td>{{ $item->entry->entry_date->format('d-M-Y') }}</td><td><a href="{{ route('journal.show', $item->entry) }}">{{ $item->entry->entry_no }}</a></td>
                <td>{{ $item->entry->description }} @if ($item->entry->reference)<span class="small text-muted">· {{ $item->entry->reference }}</span>@endif</td>
                <td class="text-end">{{ number_format((float) $item->debit - (float) $item->credit, 2) }}</td></tr>
        @empty<tr><td colspan="4" class="text-center text-muted py-3">No unmatched book transactions in this period.</td></tr>@endforelse</tbody>
    </table></div>
</div>

@if ($reconciliation->status === 'draft')
    <div class="card">
        <div class="card-header fw-semibold">Add a statement line manually</div>
        <div class="card-body"><form method="POST" action="{{ route('bank-reconciliations.lines.store', $reconciliation) }}" class="row g-2">
            @csrf
            <div class="col-md-2"><label class="form-label">Date</label><input type="date" name="statement_date" min="{{ $reconciliation->period_start->toDateString() }}" max="{{ $reconciliation->period_end->toDateString() }}" value="{{ old('statement_date') }}" class="form-control" required></div>
            <div class="col-md-4"><label class="form-label">Description</label><input name="description" value="{{ old('description') }}" maxlength="255" class="form-control" required></div>
            <div class="col-md-2"><label class="form-label">Reference</label><input name="reference" value="{{ old('reference') }}" maxlength="60" class="form-control"></div>
            <div class="col-md-2"><label class="form-label">Signed amount</label><input name="signed_amount" value="{{ old('signed_amount') }}" inputmode="decimal" class="form-control text-end" placeholder="-250.00" required></div>
            <div class="col-md-2 d-flex align-items-end">@can('bank_reconciliations.update')<button class="btn btn-primary w-100">Add line</button>@endcan</div>
        </form><div class="small text-muted mt-2">Positive values are deposits; negative values are withdrawals. Reconciliation never edits a journal entry.</div></div>
    </div>
@endif
<a href="{{ route('bank-reconciliations.index') }}" class="btn btn-light mt-3">Back to reconciliations</a>
@endsection
