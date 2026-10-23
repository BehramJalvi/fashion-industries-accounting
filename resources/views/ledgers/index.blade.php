@extends('layouts.app')
@section('title', 'Ledgers')
@section('content')
@php $m = fn (int $c) => \App\Support\Money::display($c); @endphp
<div class="card mb-3 no-print"><div class="card-body">
    <div class="mb-2">
        @foreach ($presets as $k => $l)<a class="btn btn-sm btn-{{ $preset === $k ? 'primary' : 'outline-secondary' }} me-1 mb-1" href="{{ route('ledgers.index', ['preset' => $k, 'from' => $from, 'to' => $to]) }}">{{ $l }}</a>@endforeach
        <a class="btn btn-sm btn-{{ $allLedgers !== null ? 'primary' : 'outline-secondary' }} me-1 mb-1" href="{{ route('ledgers.index', ['all' => 1, 'from' => $from, 'to' => $to]) }}">All account ledgers</a>
    </div>
    <form method="GET" class="row g-2 align-items-end">
        @if ($preset)<input type="hidden" name="preset" value="{{ $preset }}">@elseif ($allLedgers !== null)<input type="hidden" name="all" value="1">@else
        <div class="col-md-3"><label class="form-label small">Account</label><select name="account_id" class="form-select form-select-sm"><option value="">Choose an account…</option>
            @foreach (['asset' => 'Assets', 'liability' => 'Liabilities', 'equity' => 'Equity', 'revenue' => 'Revenue', 'cogs' => 'Cost of goods sold', 'expense' => 'Expenses'] as $t => $label)
                @if (isset($accounts[$t]))<optgroup label="{{ $label }}">@foreach ($accounts[$t] as $a)<option value="{{ $a->id }}" @selected(request('account_id') == $a->id)>{{ $a->code }} · {{ $a->name }}</option>@endforeach</optgroup>@endif
            @endforeach</select></div>@endif
        <div class="col-md-2"><label class="form-label small">From</label><input type="date" name="from" value="{{ $from }}" class="form-control form-control-sm"></div>
        <div class="col-md-2"><label class="form-label small">To</label><input type="date" name="to" value="{{ $to }}" class="form-control form-control-sm"></div>
        @if ($allLedgers === null)
        <div class="col-md-2"><label class="form-label small">Customer / supplier</label><select name="party" class="form-select form-select-sm"><option value="">Anyone</option>
            @foreach ($parties as $group => $list)@if ($list)<optgroup label="{{ $group }}">@foreach ($list as $p)<option value="{{ $p['v'] }}" @selected(request('party') === $p['v'])>{{ $p['n'] }}</option>@endforeach</optgroup>@endif @endforeach</select></div>
        <div class="col-md-2"><label class="form-label small">Transaction type</label><select name="voucher_type" class="form-select form-select-sm"><option value="">All</option>@foreach ($voucherTypes as $t)<option @selected(request('voucher_type') === $t)>{{ $t }}</option>@endforeach</select></div>
        @endif
        <div class="col-md-1"><button class="btn btn-sm btn-outline-secondary w-100">Show</button></div>
    </form>
    @if ($allLedgers !== null)
        <div class="small text-muted mt-2">To select one account instead, <a href="{{ route('ledgers.index', ['from' => $from, 'to' => $to]) }}">return to account selection</a>.</div>
    @else
        <div class="small text-muted mt-2">Customer and supplier statements: open the party and press <em>Ledger</em>. Inventory by product: <a href="{{ route('inventory.ledger') }}">Inventory ledger</a>.</div>
    @endif
</div></div>

@if ($allLedgers !== null)
    @include('reports._toolbar', ['title' => 'All Account Ledgers', 'subtitle' => $from . ' to ' . $to, 'showExport' => false])
    @push('styles')
        <style>@media print { .ledger-account-next { break-before: page; page-break-before: always; } .ledger-account .table-responsive { overflow: visible !important; } }</style>
    @endpush
    @forelse ($allLedgers as $ledger)
        <section class="card mb-3 ledger-account {{ $loop->first ? '' : 'ledger-account-next' }}">
            <div class="card-header fw-semibold">{{ $ledger['account']->code }} · {{ $ledger['account']->name }}</div>
            <div class="table-responsive"><table class="table table-sm align-middle mb-0">
                <thead class="table-light"><tr><th>Date</th><th>Entry</th><th>Type</th><th>Account</th><th>Party</th><th>Reference / description</th><th class="text-end">Debit</th><th class="text-end">Credit</th><th class="text-end">Balance</th></tr></thead>
                <tbody>
                <tr class="table-light fst-italic"><td colspan="8">Opening balance</td><td class="text-end">{{ $m($ledger['statement']['opening']) }}</td></tr>
                @forelse ($ledger['statement']['rows'] as $r)
                    <tr class="{{ $r['status'] === 'cancelled' ? 'text-muted' : '' }}"><td class="text-nowrap">{{ \Carbon\Carbon::parse($r['date'])->format('d-M-Y') }}</td><td><a href="{{ route('journal.show', $r['entry_id']) }}">{{ $r['entry_no'] }}</a></td>
                        <td>{{ $r['voucher_type'] }}@if ($r['status'] === 'cancelled') <span class="badge text-bg-secondary">cancelled</span>@endif</td><td class="text-nowrap">{{ $r['account'] }}</td><td>{{ $r['party'] }}</td>
                        <td>{{ $r['reference'] }} {{ $r['description'] }}</td><td class="text-end">{{ $r['debit'] ? $m($r['debit']) : '' }}</td><td class="text-end">{{ $r['credit'] ? $m($r['credit']) : '' }}</td>
                        <td class="text-end">{{ $m($r['balance']) }}</td></tr>
                @empty<tr><td colspan="9" class="text-center text-muted py-4">No transactions in this period.</td>@endforelse
                </tbody>
                <tfoot class="table-light fw-bold"><tr><td colspan="6" class="text-end">Totals / closing balance</td><td class="text-end">{{ $m($ledger['statement']['debit']) }}</td><td class="text-end">{{ $m($ledger['statement']['credit']) }}</td><td class="text-end">{{ $m($ledger['statement']['closing']) }}</td></tr></tfoot>
            </table></div>
        </section>
    @empty
        <div class="card"><div class="card-body text-muted">There are no accounts to print.</div></div>
    @endforelse
@elseif (! $statement)
    <div class="card"><div class="card-body text-muted">Choose an account, or one of the books above (cash, bank, expenses, owner capital), to see its ledger.</div></div>
@else
    @include('reports._toolbar', ['title' => 'Ledger: ' . implode(', ', $selected), 'subtitle' => $from . ' to ' . $to])
    <div class="card"><div class="table-responsive"><table class="table table-sm align-middle mb-0">
        <thead class="table-light"><tr><th>Date</th><th>Entry</th><th>Type</th><th>Account</th><th>Party</th><th>Reference / description</th><th class="text-end">Debit</th><th class="text-end">Credit</th><th class="text-end">Balance</th></tr></thead>
        <tbody>
        @if ($statement['running'])<tr class="table-light fst-italic"><td colspan="8">Opening balance</td><td class="text-end">{{ $m($statement['opening']) }}</td></tr>@endif
        @forelse ($statement['rows'] as $r)
            <tr class="{{ $r['status'] === 'cancelled' ? 'text-muted' : '' }}"><td class="text-nowrap">{{ \Carbon\Carbon::parse($r['date'])->format('d-M-Y') }}</td><td><a href="{{ route('journal.show', $r['entry_id']) }}">{{ $r['entry_no'] }}</a></td>
                <td>{{ $r['voucher_type'] }}@if ($r['status'] === 'cancelled') <span class="badge text-bg-secondary">cancelled</span>@endif</td><td class="text-nowrap">{{ $r['account'] }}</td><td>{{ $r['party'] }}</td>
                <td>{{ $r['reference'] }} {{ $r['description'] }}</td><td class="text-end">{{ $r['debit'] ? $m($r['debit']) : '' }}</td><td class="text-end">{{ $r['credit'] ? $m($r['credit']) : '' }}</td>
                <td class="text-end">{{ $r['balance'] === null ? '' : $m($r['balance']) }}</td></tr>
        @empty<tr><td colspan="9" class="text-center text-muted py-4">No transactions in this period.</td></tr>@endforelse
        </tbody>
        <tfoot class="table-light fw-bold"><tr><td colspan="6" class="text-end">Totals{{ $statement['running'] ? ' / closing balance' : '' }}</td><td class="text-end">{{ $m($statement['debit']) }}</td><td class="text-end">{{ $m($statement['credit']) }}</td><td class="text-end">{{ $statement['closing'] === null ? '' : $m($statement['closing']) }}</td></tr></tfoot>
    </table></div></div>
    @unless ($statement['running'])<p class="small text-muted mt-2">A transaction-type filter shows only those rows, so no running balance is shown.</p>@endunless
@endif
@endsection
