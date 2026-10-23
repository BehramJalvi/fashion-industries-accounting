@extends('layouts.app')
@section('title', 'Balance Sheet')
@section('content')
@php $m = fn (int $c) => \App\Support\Money::display($c); @endphp
<form method="GET" class="card card-body mb-3 no-print"><div class="row g-2 align-items-end">
    <div class="col-md-3"><label class="form-label small">As at</label><input type="date" name="to" value="{{ $to }}" class="form-control form-control-sm"></div>
    <div class="col-md-2"><button class="btn btn-sm btn-outline-secondary w-100">Show</button></div>
</div></form>
@include('reports._toolbar', ['title' => 'Balance Sheet', 'subtitle' => 'As at ' . $to])
<div class="row g-3">
    <div class="col-lg-6"><div class="card"><div class="card-header fw-semibold">Assets</div><table class="table table-sm mb-0"><tbody>
        @forelse ($bs['assets'] as $l)<tr><td>{{ $l['code'] }} {{ $l['name'] }}</td><td class="text-end">{{ $m($l['amount']) }}</td></tr>@empty<tr><td class="text-muted">Nothing yet.</td><td></td></tr>@endforelse
        </tbody><tfoot class="table-light fw-bold"><tr><td>Total assets</td><td class="text-end">{{ $m($bs['total_assets']) }}</td></tr></tfoot></table></div></div>
    <div class="col-lg-6">
        <div class="card mb-3"><div class="card-header fw-semibold">Liabilities</div><table class="table table-sm mb-0"><tbody>
            @forelse ($bs['liabilities'] as $l)<tr><td>{{ $l['code'] }} {{ $l['name'] }}</td><td class="text-end">{{ $m($l['amount']) }}</td></tr>@empty<tr><td class="text-muted">None.</td><td></td></tr>@endforelse
            </tbody><tfoot class="table-light fw-bold"><tr><td>Total liabilities</td><td class="text-end">{{ $m($bs['total_liabilities']) }}</td></tr></tfoot></table></div>
        <div class="card"><div class="card-header fw-semibold">Equity</div><table class="table table-sm mb-0"><tbody>
            @foreach ($bs['equity'] as $l)<tr><td>{{ $l['code'] }} {{ $l['name'] }}</td><td class="text-end">{{ $m($l['amount']) }}</td></tr>@endforeach
            <tr><td>Profit / (loss) not yet closed to Retained Earnings</td><td class="text-end">{{ $m($bs['profit']) }}</td></tr>
            </tbody><tfoot class="table-light fw-bold"><tr><td>Total equity</td><td class="text-end">{{ $m($bs['total_equity']) }}</td></tr>
            <tr><td>Total liabilities and equity</td><td class="text-end">{{ $m($bs['total_liabilities'] + $bs['total_equity']) }}</td></tr></tfoot></table></div>
    </div>
</div>
<div class="mt-3 {{ $bs['balanced'] ? 'text-success' : 'text-danger fw-bold' }}">{{ $bs['balanced'] ? 'Balanced: assets equal liabilities plus equity.' : 'OUT OF BALANCE by ' . $m($bs['difference']) . '. This should never happen; please investigate.' }}</div>
@endsection
