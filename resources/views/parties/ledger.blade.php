@extends('layouts.app')
@section('title', $cfg['labels']['singular'] . ' ledger')
@section('content')
@php
    $L = $cfg['labels'];
    $m = fn (int $c) => number_format(abs($c) / 100, 2);
    $tag = fn (int $c) => $c < 0 ? ' ' . $L['negative'] : '';
@endphp
<div class="card mb-3 no-print"><div class="card-body">
    <form class="row g-2" method="GET">
        <div class="col-md-3"><label class="form-label small">From</label><input type="date" name="from" value="{{ request('from') }}" class="form-control form-control-sm"></div>
        <div class="col-md-3"><label class="form-label small">To</label><input type="date" name="to" value="{{ request('to') }}" class="form-control form-control-sm"></div>
        <div class="col-md-6 d-flex align-items-end gap-2">
            <button class="btn btn-sm btn-outline-secondary">Show</button>
            <button type="button" class="btn btn-sm btn-outline-primary" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
            <a href="{{ route($cfg['prefix'] . '.index') }}" class="btn btn-sm btn-light ms-auto">Back</a>
        </div>
    </form>
</div></div>

<div class="card">
    <div class="card-header">
        <div class="fw-semibold fs-5">{{ $party->name }}@if (! empty($party->code)) <small class="text-muted">({{ $party->code }})</small>@endif</div>
        <div class="text-muted small">{{ \App\Models\Setting::get('company_name', 'Fashion Industries INC') }} · {{ $L['singular'] }} ledger
            @if (request('from') || request('to')) · {{ request('from') ?: 'start' }} to {{ request('to') ?: 'today' }}@endif</div>
    </div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead class="table-light"><tr><th>Date</th><th>Entry</th><th>Type</th><th>Reference</th><th>Description</th>
                <th class="text-end">Debit</th><th class="text-end">Credit</th><th class="text-end">Balance</th></tr></thead>
            <tbody>
            @if (request('from'))
                <tr class="table-light"><td colspan="7" class="fst-italic">Opening balance</td><td class="text-end">{{ $m($statement['opening']) }}{{ $tag($statement['opening']) }}</td></tr>
            @endif
            @forelse ($statement['rows'] as $r)
                <tr class="{{ $r['status'] === 'cancelled' ? 'text-muted' : '' }}">
                    <td class="text-nowrap">{{ \Carbon\Carbon::parse($r['date'])->format('d-M-Y') }}</td>
                    <td>@can('journal.view')<a href="{{ route('journal.show', $r['entry_id']) }}">{{ $r['entry_no'] }}</a>@else{{ $r['entry_no'] }}@endcan</td>
                    <td>{{ $r['voucher_type'] }}@if ($r['status'] === 'cancelled') <span class="badge text-bg-secondary">cancelled</span>@endif</td>
                    <td>{{ $r['reference'] }}</td>
                    <td>{{ $r['description'] }}</td>
                    <td class="text-end">{{ $r['debit'] ? $m($r['debit']) : '' }}</td>
                    <td class="text-end">{{ $r['credit'] ? $m($r['credit']) : '' }}</td>
                    <td class="text-end">{{ $m($r['balance']) }}{{ $tag($r['balance']) }}</td>
                </tr>
            @empty
                <tr><td colspan="8" class="text-center text-muted py-4">No transactions in this period.</td></tr>
            @endforelse
            </tbody>
            <tfoot class="table-light fw-semibold">
                <tr><td colspan="5" class="text-end">Totals / closing {{ strtolower($L['balance']) }}</td>
                    <td class="text-end">{{ $m($statement['debit']) }}</td><td class="text-end">{{ $m($statement['credit']) }}</td>
                    <td class="text-end">{{ $m($statement['closing']) }}{{ $tag($statement['closing']) }}</td></tr>
            </tfoot>
        </table>
    </div>
</div>
@endsection
