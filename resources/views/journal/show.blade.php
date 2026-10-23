@extends('layouts.app')
@section('title', 'Journal ' . $entry->entry_no)
@section('content')
<div class="card mb-3"><div class="card-body">
    <div class="row">
        <div class="col-md-8">
            <h5 class="mb-1">{{ $entry->entry_no }}
                <span class="badge text-bg-{{ $entry->status === 'posted' ? 'success' : 'secondary' }}">{{ $entry->status }}</span>
                <span class="badge text-bg-light border">{{ $entry->voucher_type }}</span></h5>
            <div class="text-muted">{{ $entry->entry_date->format('d-M-Y') }}@if ($entry->reference) · Ref {{ $entry->reference }}@endif</div>
            <p class="mt-2 mb-1">{{ $entry->description }}</p>
            <div class="small text-muted">Posted by {{ $entry->creator?->name ?? 'system' }} on {{ $entry->posted_at?->format('d-M-Y H:i') }}</div>
            @if ($entry->reverses)<div class="mt-2">Reversal of <a href="{{ route('journal.show', $entry->reverses) }}">{{ $entry->reverses->entry_no }}</a></div>@endif
            @if ($entry->reversal)<div class="mt-2">Cancelled by <a href="{{ route('journal.show', $entry->reversal) }}">{{ $entry->reversal->entry_no }}</a></div>@endif
        </div>
        @can('journal.cancel')
            @if ($entry->status === 'posted' && $entry->voucher_type === 'journal' && ! $entry->source_type)
                <div class="col-md-4">
                    <form method="POST" action="{{ route('journal.cancel', $entry) }}" onsubmit="return confirm('Cancel this entry? A reversing entry will be posted.')">
                        @csrf
                        <label class="form-label">Cancel this entry</label>
                        <input name="reason" class="form-control mb-2" placeholder="Reason (required)" required maxlength="200">
                        <button class="btn btn-outline-danger btn-sm">Cancel &amp; reverse</button>
                    </form>
                </div>
            @endif
        @endcan
    </div>
</div></div>

<div class="card">
    <table class="table table-sm align-middle mb-0">
        <thead class="table-light"><tr><th>#</th><th>Account</th><th>Party</th><th>Narration</th><th class="text-end">Debit</th><th class="text-end">Credit</th></tr></thead>
        <tbody>
        @foreach ($entry->lines as $l)
            <tr>
                <td>{{ $l->line_no }}</td>
                <td>{{ $l->account->code }} · {{ $l->account->name }}</td>
                <td>{{ $l->party_type ? ucfirst($l->party_type) . ' #' . $l->party_id : '' }}</td>
                <td>{{ $l->narration }}</td>
                <td class="text-end">{{ (float) $l->debit > 0 ? number_format((float) $l->debit, 2) : '' }}</td>
                <td class="text-end">{{ (float) $l->credit > 0 ? number_format((float) $l->credit, 2) : '' }}</td>
            </tr>
        @endforeach
        </tbody>
        <tfoot class="table-light fw-semibold"><tr><td colspan="4" class="text-end">Total</td>
            <td class="text-end">{{ number_format((float) $entry->totalDebit(), 2) }}</td><td class="text-end">{{ number_format((float) $entry->totalCredit(), 2) }}</td></tr></tfoot>
    </table>
</div>
<a href="{{ route('journal.index') }}" class="btn btn-light mt-3">Back</a>
@endsection
