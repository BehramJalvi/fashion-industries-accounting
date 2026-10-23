@extends('layouts.app')
@section('title', 'Cash Flow')
@section('content')
@php $m = fn (int $c) => \App\Support\Money::display($c); @endphp
<form method="GET" class="card card-body mb-3 no-print"><div class="row g-2 align-items-end">
    <div class="col-md-3"><label class="form-label small">From</label><input type="date" name="from" value="{{ $from }}" class="form-control form-control-sm"></div>
    <div class="col-md-3"><label class="form-label small">To</label><input type="date" name="to" value="{{ $to }}" class="form-control form-control-sm"></div>
    <div class="col-md-2"><button class="btn btn-sm btn-outline-secondary w-100">Show</button></div>
</div></form>
@include('reports._toolbar', ['title' => 'Cash Flow', 'subtitle' => $from . ' to ' . $to])
<div class="card mb-3"><table class="table table-sm align-middle mb-0" style="max-width:820px"><thead class="table-light"><tr><th>Item</th><th class="text-end">Money in</th><th class="text-end">Money out</th><th class="text-end">Net</th></tr></thead><tbody>
    <tr class="fw-semibold"><td>Opening cash and bank</td><td></td><td></td><td class="text-end">{{ $m($cf['opening']) }}</td></tr>
    @forelse ($cf['rows'] as $r)<tr><td class="ps-4">{{ $r['label'] }}</td><td class="text-end">{{ $r['in'] ? $m($r['in']) : '' }}</td><td class="text-end">{{ $r['out'] ? $m($r['out']) : '' }}</td><td class="text-end">{{ $m($r['net']) }}</td></tr>
    @empty<tr><td colspan="4" class="text-muted ps-4">No cash or bank movement in this period.</td></tr>@endforelse
    </tbody><tfoot class="table-light fw-bold"><tr><td>Closing cash and bank</td><td class="text-end">{{ $m($cf['in']) }}</td><td class="text-end">{{ $m($cf['out']) }}</td><td class="text-end">{{ $m($cf['closing']) }}</td></tr></tfoot></table></div>
<div class="card"><div class="card-header fw-semibold">By account</div><table class="table table-sm mb-0" style="max-width:820px"><thead class="table-light"><tr><th>Account</th><th class="text-end">Opening</th><th class="text-end">Change</th><th class="text-end">Closing</th></tr></thead><tbody>
    @foreach ($cf['accounts'] as $a)<tr><td>{{ $a['name'] }}</td><td class="text-end">{{ $m($a['opening']) }}</td><td class="text-end">{{ $m($a['change']) }}</td><td class="text-end">{{ $m($a['closing']) }}</td></tr>@endforeach</tbody></table></div>
@endsection
