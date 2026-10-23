@extends('layouts.app')
@section('title', 'Stock Valuation')
@section('content')
@php
    $m = fn (int $c) => \App\Support\Money::display($c);
    $q = fn (int $milli) => rtrim(rtrim(number_format($milli / 1000, 3), '0'), '.') ?: '0';
@endphp
<form method="GET" class="card card-body mb-3 no-print"><div class="row g-2 align-items-end">
    <div class="col-md-3"><label class="form-label small">As at (blank = now)</label><input type="date" name="as_of" value="{{ $asOf }}" class="form-control form-control-sm"></div>
    <div class="col-md-3"><label class="form-label small">Category</label><select name="category_id" class="form-select form-select-sm"><option value="">All</option>@foreach ($categories as $c)<option value="{{ $c->id }}" @selected($categoryId == $c->id)>{{ $c->name }}</option>@endforeach</select></div>
    <div class="col-md-3"><div class="form-check"><input class="form-check-input" type="checkbox" name="show_zero" value="1" id="z" @checked($showZero)><label class="form-check-label small" for="z">Include products with no stock</label></div></div>
    <div class="col-md-2"><button class="btn btn-sm btn-outline-secondary w-100">Show</button></div>
</div></form>
@include('reports._toolbar', ['title' => 'Stock Valuation (weighted average cost)', 'subtitle' => $asOf ? 'As at ' . $asOf : 'Current'])
@if ($v['difference'] !== 0)<div class="alert alert-warning">The stock ledger ({{ $m($v['gl_value'] + $v['difference']) }}) does not match the Inventory account ({{ $m($v['gl_value']) }}) by {{ $m($v['difference']) }}. This should be zero; please investigate.</div>@endif
<div class="card"><div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
    <thead class="table-light"><tr><th>Code</th><th>Product</th><th>Category</th><th class="text-end">Quantity</th><th class="text-end">Average cost</th><th class="text-end">Value</th></tr></thead>
    <tbody>
    @forelse ($v['rows'] as $r)<tr><td>{{ $r['code'] }}</td><td>{{ $r['name'] }}</td><td>{{ $r['category'] }}</td><td class="text-end">{{ $q($r['qty']) }} {{ $r['unit'] }}</td><td class="text-end">{{ number_format((float) $r['avg'], 4) }}</td><td class="text-end">{{ $m($r['value']) }}</td></tr>
    @empty<tr><td colspan="6" class="text-center text-muted py-4">No stock.</td></tr>@endforelse
    </tbody>
    <tfoot class="table-light">
        @foreach ($v['categories'] as $name => $c)<tr><td colspan="5" class="text-end text-muted">{{ $name }}</td><td class="text-end">{{ $m($c['value']) }}</td></tr>@endforeach
        <tr class="fw-bold"><td colspan="5" class="text-end">Total stock value</td><td class="text-end">{{ $m($v['total_value']) }}</td></tr>
        <tr><td colspan="5" class="text-end text-muted">Inventory account (control)</td><td class="text-end text-muted">{{ $m($v['gl_value']) }}</td></tr>
    </tfoot>
</table></div></div>
@endsection
