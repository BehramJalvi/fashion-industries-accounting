@extends('layouts.app')
@section('title', $title)
@section('content')
@php $m = fn (int $c) => $c ? \App\Support\Money::display($c) : '—'; @endphp
<form method="GET" class="card card-body mb-3 no-print"><div class="row g-2 align-items-end">
    <div class="col-md-3"><label class="form-label small">Age invoices as at</label><input type="date" name="as_of" value="{{ $asOf }}" class="form-control form-control-sm"></div>
    <div class="col-md-2"><button class="btn btn-sm btn-outline-secondary w-100">Show</button></div>
    <div class="col-md-7 small text-muted">Open amounts are today's position. The date only decides which age band an invoice falls in (days past its due date).</div>
</div></form>
@include('reports._toolbar', ['title' => $title, 'subtitle' => 'Aged as at ' . $asOf])
@foreach ($sections as $s)
<div class="card mb-3"><div class="card-header fw-semibold">{{ $s['title'] }}</div><div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
    <thead class="table-light"><tr><th>Name</th>@foreach ($buckets as $label)<th class="text-end">{{ $label }}</th>@endforeach<th class="text-end" title="Opening balance, advances and money on account that are not on an open invoice">Opening / on account</th><th class="text-end">Total</th></tr></thead>
    <tbody>
    @forelse ($s['data']['rows'] as $r)
        <tr><td><a href="{{ route($s['ledger'], $r['id']) }}">{{ $r['name'] }}</a></td>
            @foreach (array_keys($buckets) as $k)<td class="text-end">{{ $m($r[$k]) }}</td>@endforeach
            <td class="text-end">{{ $m($r['other']) }}</td><td class="text-end fw-semibold {{ $r['total'] < 0 ? 'text-danger' : '' }}">{{ \App\Support\Money::display($r['total']) }}</td></tr>
    @empty<tr><td colspan="8" class="text-center text-muted py-3">Nothing outstanding.</td></tr>@endforelse
    </tbody>
    <tfoot class="table-light fw-bold"><tr><td>Total</td>@foreach (array_keys($buckets) as $k)<td class="text-end">{{ $m($s['data']['totals'][$k]) }}</td>@endforeach<td class="text-end">{{ $m($s['data']['totals']['other']) }}</td><td class="text-end">{{ \App\Support\Money::display($s['data']['totals']['total']) }}</td></tr></tfoot>
</table></div></div>
@endforeach
@endsection
