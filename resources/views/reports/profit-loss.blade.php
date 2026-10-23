@extends('layouts.app')
@section('title', 'Profit & Loss')
@section('content')
@php
    $m = fn (int $c) => \App\Support\Money::display($c);
    $lines = fn (array $ls) => $ls;
@endphp
<form method="GET" class="card card-body mb-3 no-print"><div class="row g-2 align-items-end">
    <div class="col-md-3"><label class="form-label small">From</label><input type="date" name="from" value="{{ $from }}" class="form-control form-control-sm"></div>
    <div class="col-md-3"><label class="form-label small">To</label><input type="date" name="to" value="{{ $to }}" class="form-control form-control-sm"></div>
    <div class="col-md-2"><button class="btn btn-sm btn-outline-secondary w-100">Show</button></div>
</div></form>
@include('reports._toolbar', ['title' => 'Profit & Loss', 'subtitle' => $from . ' to ' . $to])
<div class="card"><table class="table table-sm align-middle mb-0" style="max-width:760px">
    <tbody>
    <tr class="table-light fw-semibold"><td colspan="2">Revenue</td></tr>
    @foreach ($pl['sales'] as $l)<tr><td class="ps-4">{{ $l['code'] }} {{ $l['name'] }}</td><td class="text-end">{{ $m($l['amount']) }}</td></tr>@endforeach
    <tr class="fw-semibold"><td>Gross sales</td><td class="text-end">{{ $m($pl['gross_sales']) }}</td></tr>
    @foreach ($pl['deductions'] as $l)<tr><td class="ps-4">Less: {{ $l['name'] }}</td><td class="text-end">({{ $m($l['amount']) }})</td></tr>@endforeach
    @foreach ($pl['other_income'] as $l)<tr><td class="ps-4">{{ $l['name'] }}</td><td class="text-end">{{ $m($l['amount']) }}</td></tr>@endforeach
    <tr class="fw-bold border-top"><td>Net sales</td><td class="text-end">{{ $m($pl['net_sales']) }}</td></tr>

    <tr class="table-light fw-semibold"><td colspan="2">Less: cost of goods sold</td></tr>
    @foreach ($pl['cogs'] as $l)<tr><td class="ps-4">{{ $l['code'] }} {{ $l['name'] }}</td><td class="text-end">{{ $m($l['amount']) }}</td></tr>@endforeach
    <tr class="fw-semibold"><td>Total cost of goods sold</td><td class="text-end">({{ $m($pl['total_cogs']) }})</td></tr>
    <tr class="fw-bold border-top"><td>Gross profit @if ($pl['margin'] !== null)<small class="text-muted fw-normal">({{ $pl['margin'] }}% of net sales)</small>@endif</td><td class="text-end">{{ $m($pl['gross_profit']) }}</td></tr>

    <tr class="table-light fw-semibold"><td colspan="2">Less: operating expenses</td></tr>
    @foreach ($pl['expenses'] as $l)<tr><td class="ps-4">{{ $l['code'] }} {{ $l['name'] }}</td><td class="text-end">{{ $m($l['amount']) }}</td></tr>@endforeach
    <tr class="fw-semibold"><td>Total operating expenses</td><td class="text-end">({{ $m($pl['total_expenses']) }})</td></tr>
    <tr class="fw-bold fs-5 border-top border-dark {{ $pl['net_profit'] < 0 ? 'text-danger' : 'text-success' }}"><td>Net {{ $pl['net_profit'] < 0 ? 'loss' : 'profit' }}</td><td class="text-end">{{ $m($pl['net_profit']) }}</td></tr>
    </tbody>
</table></div>
<p class="small text-muted mt-2">Built from the journal, so cancelled transactions net out. Processing / dyeing charges and inventory gains and losses are part of cost of goods sold.</p>
@endsection
