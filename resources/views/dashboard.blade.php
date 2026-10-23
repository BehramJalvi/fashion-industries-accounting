@extends('layouts.app')
@section('title', 'Dashboard')
@section('content')
@php
    $m = fn (int $c) => \App\Support\Money::display($c);
    $q = fn (int $milli) => rtrim(rtrim(number_format($milli / 1000, 3), '0'), '.') ?: '0';
    $a = $d['attention'];
    $warn = $showMoney && ($d['inventory']['difference'] !== 0 || $a['journal_difference'] !== 0);
@endphp

<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
    <div><h5 class="mb-0">Welcome, {{ auth()->user()->name }}</h5><div class="text-muted small">{{ now()->format('l, d F Y') }}@if ($showMoney) · showing <strong>{{ $label }}</strong> ({{ \Carbon\Carbon::parse($from)->format('d M') }} – {{ \Carbon\Carbon::parse($to)->format('d M Y') }})@endif</div></div>
    @if ($showMoney)
    <form method="GET" class="d-flex flex-wrap gap-1 align-items-center">
        @foreach (['month' => 'This month', 'fy' => 'This fiscal year', 'last30' => 'Last 30 days'] as $k => $l)
            <a class="btn btn-sm btn-{{ $period === $k ? 'primary' : 'outline-secondary' }}" href="{{ route('dashboard', ['period' => $k]) }}">{{ $l }}</a>
        @endforeach
        <input type="hidden" name="period" value="custom">
        <input type="date" name="from" value="{{ $period === 'custom' ? $from : '' }}" class="form-control form-control-sm" style="width:140px" required>
        <input type="date" name="to" value="{{ $period === 'custom' ? $to : '' }}" class="form-control form-control-sm" style="width:140px" required>
        <button class="btn btn-sm btn-{{ $period === 'custom' ? 'primary' : 'outline-secondary' }}">Go</button>
    </form>
    @endif
</div>

@if ($warn)
    <div class="alert alert-danger">
        @if ($d['inventory']['difference'] !== 0)<div><strong>Inventory does not reconcile.</strong> The stock ledger differs from the Inventory account by {{ $m($d['inventory']['difference']) }}. <a href="{{ route('inventory.index') }}">Check inventory</a>.</div>@endif
        @if ($a['journal_difference'] !== 0)<div><strong>The journal is out of balance by {{ $m($a['journal_difference']) }}.</strong> This should never happen; please investigate before relying on any report.</div>@endif
    </div>
@endif

@if ($showMoney)
<div class="row g-3 mb-3">
    <div class="col-md-6 col-xl-3"><div class="card h-100"><div class="card-body">
        <div class="text-muted small">Net sales</div><div class="fs-4 fw-semibold">{{ $m($d['sales']['net']) }}</div>
        <div class="small text-muted">{{ $d['sales']['count'] }} invoice{{ $d['sales']['count'] === 1 ? '' : 's' }} · gross profit {{ $m($d['profit']['gross']) }}@if ($d['profit']['margin'] !== null) ({{ $d['profit']['margin'] }}%)@endif</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card h-100"><div class="card-body">
        <div class="text-muted small">Purchases</div><div class="fs-4 fw-semibold">{{ $m($d['purchases']['net']) }}</div>
        <div class="small text-muted">{{ $d['purchases']['count'] }} bill{{ $d['purchases']['count'] === 1 ? '' : 's' }}@if ($d['purchases']['returned']) · returns {{ $m($d['purchases']['returned']) }}@endif</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card h-100"><div class="card-body">
        <div class="text-muted small">Net {{ $d['profit']['net'] < 0 ? 'loss' : 'profit' }}</div><div class="fs-4 fw-semibold {{ $d['profit']['net'] < 0 ? 'text-danger' : 'text-success' }}">{{ $m($d['profit']['net']) }}</div>
        <div class="small text-muted">after expenses of {{ $m($d['profit']['expenses']) }} · <a href="{{ route('reports.profit-loss', ['from' => $from, 'to' => $to]) }}">Profit &amp; Loss</a></div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card h-100"><div class="card-body">
        <div class="text-muted small">Sales tax payable (output less input)</div><div class="fs-4 fw-semibold">{{ $m($d['tax']['payable']) }}</div>
        <div class="small text-muted">as at today</div></div></div></div>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-6 col-xl"><a class="text-decoration-none text-reset" href="{{ route('reports.receivables') }}"><div class="card h-100 border-start border-4 border-primary"><div class="card-body">
        <div class="text-muted small">Receivables</div><div class="fs-5 fw-semibold">{{ $m($d['receivables']['total']) }}</div>
        <div class="small {{ $d['receivables']['overdue'] > 0 ? 'text-danger' : 'text-muted' }}">{{ $d['receivables']['overdue'] > 0 ? $m($d['receivables']['overdue']) . ' overdue' : 'nothing overdue' }}</div></div></div></a></div>
    <div class="col-md-6 col-xl"><a class="text-decoration-none text-reset" href="{{ route('reports.payables') }}"><div class="card h-100 border-start border-4 border-warning"><div class="card-body">
        <div class="text-muted small">Payables</div><div class="fs-5 fw-semibold">{{ $m($d['payables']['total']) }}</div>
        <div class="small text-muted">suppliers {{ $m($d['payables']['suppliers']) }} · employees {{ $m($d['payables']['employees']) }}</div></div></div></a></div>
    <div class="col-md-6 col-xl"><a class="text-decoration-none text-reset" href="{{ route('ledgers.index', ['preset' => 'cash']) }}"><div class="card h-100 border-start border-4 border-success"><div class="card-body">
        <div class="text-muted small">Cash balance</div><div class="fs-5 fw-semibold">{{ $m($d['cash']['cash']) }}</div><div class="small text-muted">cash in hand</div></div></div></a></div>
    <div class="col-md-6 col-xl"><a class="text-decoration-none text-reset" href="{{ route('ledgers.index', ['preset' => 'bank']) }}"><div class="card h-100 border-start border-4 border-info"><div class="card-body">
        <div class="text-muted small">Bank balance</div><div class="fs-5 fw-semibold">{{ $m($d['cash']['bank']) }}</div><div class="small text-muted">all bank accounts</div></div></div></a></div>
    <div class="col-md-6 col-xl"><a class="text-decoration-none text-reset" href="{{ route('reports.stock-valuation') }}"><div class="card h-100 border-start border-4 border-secondary"><div class="card-body">
        <div class="text-muted small">Inventory value</div><div class="fs-5 fw-semibold">{{ $m($d['inventory']['value']) }}</div><div class="small text-muted">weighted average cost</div></div></div></a></div>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-7"><div class="card h-100"><div class="card-header fw-semibold">Last six months</div><div class="card-body">
        @foreach ($d['trend']['months'] as $mo)
            <div class="d-flex align-items-center mb-2 small"><div style="width:70px">{{ $mo['label'] }}</div>
                <div class="flex-grow-1 me-2"><div class="bg-primary rounded" style="height:14px; width:{{ max(1, round(abs($mo['sales']) / $d['trend']['max'] * 100)) }}%"></div></div>
                <div style="width:110px" class="text-end">{{ $m($mo['sales']) }}</div>
                <div style="width:110px" class="text-end {{ $mo['profit'] < 0 ? 'text-danger' : 'text-success' }}">{{ $m($mo['profit']) }}</div></div>
        @endforeach
        <div class="d-flex small text-muted"><div style="width:70px"></div><div class="flex-grow-1">net sales</div><div style="width:110px" class="text-end"></div><div style="width:110px" class="text-end">profit / (loss)</div></div>
    </div></div></div>
    <div class="col-lg-5"><div class="card h-100"><div class="card-header fw-semibold">Cash and bank accounts</div><table class="table table-sm mb-0"><tbody>
        @forelse ($d['cash']['accounts'] as $acc)<tr><td>{{ $acc['name'] }}</td><td class="text-end {{ $acc['balance'] < 0 ? 'text-danger' : '' }}">{{ $m($acc['balance']) }}</td></tr>@empty<tr><td class="text-muted">No cash or bank movement yet.</td></tr>@endforelse
        </tbody></table></div></div>
</div>
@endif

<div class="row g-3 mb-3">
    <div class="col-lg-6"><div class="card h-100"><div class="card-header d-flex justify-content-between"><span class="fw-semibold">Low stock</span>
        @if ($d['low_stock']['count'])<span class="badge text-bg-warning">{{ $d['low_stock']['count'] }}</span>@endif</div>
        @if ($d['low_stock']['count'] === 0)<div class="card-body text-muted">Every product is at or above its minimum stock.</div>
        @else<table class="table table-sm mb-0"><thead class="table-light"><tr><th>Product</th><th class="text-end">In stock</th><th class="text-end">Minimum</th></tr></thead><tbody>
            @foreach ($d['low_stock']['items'] as $p)<tr><td>{{ $p['name'] }} <small class="text-muted">{{ $p['code'] }}</small></td><td class="text-end text-danger">{{ $q($p['qty']) }} {{ $p['unit'] }}</td><td class="text-end">{{ $q($p['min']) }}</td></tr>@endforeach
        </tbody></table>@can('inventory.view')@if ($d['low_stock']['count'] > count($d['low_stock']['items']))<div class="card-footer small"><a href="{{ route('inventory.index', ['low' => 1]) }}">See all {{ $d['low_stock']['count'] }}</a></div>@endif @endcan @endif
    </div></div>

    <div class="col-lg-6"><div class="card h-100"><div class="card-header fw-semibold">Waiting for you</div><div class="card-body">
        @php
            $visible = array_filter($a['drafts'], fn ($x) => auth()->user()->can($x['permission']));
        @endphp
        @if (! $visible)<div class="text-muted">No drafts waiting to be posted.</div>
        @else<ul class="list-unstyled mb-0">@foreach ($visible as $x)<li class="mb-1"><span class="badge text-bg-warning">{{ $x['count'] }}</span> <a href="{{ route($x['route'], ['status' => 'draft']) }}">{{ $x['label'] }}</a> saved as draft</li>@endforeach</ul>@endif
    </div></div></div>
</div>

<div class="row g-3">
    @can('sales.view')
    <div class="col-xl-4"><div class="card h-100"><div class="card-header d-flex justify-content-between"><span class="fw-semibold">Recent sales</span><a href="{{ route('sales.index') }}" class="small">All</a></div>
        <div class="list-group list-group-flush">@forelse ($d['recent']['sales'] as $s)
            <a class="list-group-item list-group-item-action d-flex justify-content-between" href="{{ route('sales.show', $s) }}"><span>{{ $s->invoice_no }} · {{ $s->customer?->name }}<br><small class="text-muted">{{ $s->sale_date->format('d-M-Y') }}</small></span><span>{{ number_format((float) $s->total, 2) }}</span></a>
        @empty<div class="list-group-item text-muted">No invoices yet.</div>@endforelse</div></div></div>
    @endcan
    @can('purchases.view')
    <div class="col-xl-4"><div class="card h-100"><div class="card-header d-flex justify-content-between"><span class="fw-semibold">Recent purchases</span><a href="{{ route('purchases.index') }}" class="small">All</a></div>
        <div class="list-group list-group-flush">@forelse ($d['recent']['purchases'] as $p)
            <a class="list-group-item list-group-item-action d-flex justify-content-between" href="{{ route('purchases.show', $p) }}"><span>{{ $p->purchase_no }} · {{ $p->supplier?->name ?? 'Walk-in' }}<br><small class="text-muted">{{ $p->purchase_date->format('d-M-Y') }}</small></span><span>{{ number_format((float) $p->total, 2) }}</span></a>
        @empty<div class="list-group-item text-muted">No purchases yet.</div>@endforelse</div></div></div>
    @endcan
    @can('journal.view')
    <div class="col-xl-4"><div class="card h-100"><div class="card-header d-flex justify-content-between"><span class="fw-semibold">Recent money movements</span><a href="{{ route('journal.index') }}" class="small">Journal</a></div>
        <div class="list-group list-group-flush">@forelse ($d['recent']['cash'] as $e)
            <a class="list-group-item list-group-item-action d-flex justify-content-between" href="{{ route('journal.show', $e) }}"><span>{{ $e->description }}<br><small class="text-muted">{{ $e->entry_date->format('d-M-Y') }} · {{ $e->voucher_type }}</small></span><span>{{ number_format((float) $e->total_amount, 2) }}</span></a>
        @empty<div class="list-group-item text-muted">Nothing yet.</div>@endforelse</div></div></div>
    @endcan
</div>
@endsection
