@extends('layouts.app')
@section('title', 'Trial Balance')
@section('content')
@php $m = fn (int $c) => $c ? \App\Support\Money::display($c) : ''; @endphp
<form method="GET" class="card card-body mb-3 no-print"><div class="row g-2 align-items-end">
    <div class="col-md-3"><label class="form-label small">Movement from</label><input type="date" name="from" value="{{ $from }}" class="form-control form-control-sm"></div>
    <div class="col-md-3"><label class="form-label small">Closing as at</label><input type="date" name="to" value="{{ $to }}" class="form-control form-control-sm"></div>
    <div class="col-md-2"><button class="btn btn-sm btn-outline-secondary w-100">Show</button></div>
</div></form>
@include('reports._toolbar', ['title' => 'Trial Balance', 'subtitle' => 'Movement ' . $from . ' to ' . $to])
<div class="card"><div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
    <thead class="table-light"><tr><th rowspan="2">Code</th><th rowspan="2">Account</th><th colspan="2" class="text-center">Opening</th><th colspan="2" class="text-center">Movement</th><th colspan="2" class="text-center">Closing</th></tr>
        <tr class="text-end"><th>Debit</th><th>Credit</th><th>Debit</th><th>Credit</th><th>Debit</th><th>Credit</th></tr></thead>
    <tbody>
    @forelse ($tb['rows'] as $r)
        <tr><td>{{ $r['code'] }}</td><td>{{ $r['name'] }}</td>
            @foreach (['open_dr', 'open_cr', 'period_dr', 'period_cr', 'close_dr', 'close_cr'] as $k)<td class="text-end">{{ $m($r[$k]) }}</td>@endforeach</tr>
    @empty<tr><td colspan="8" class="text-center text-muted py-4">No transactions yet.</td></tr>@endforelse
    </tbody>
    <tfoot class="table-light fw-bold"><tr><td colspan="2" class="text-end">Total</td>
        @foreach (['open_dr', 'open_cr', 'period_dr', 'period_cr', 'close_dr', 'close_cr'] as $k)<td class="text-end">{{ \App\Support\Money::display($tb['totals'][$k]) }}</td>@endforeach</tr></tfoot>
</table></div>
<div class="card-footer {{ $tb['balanced'] ? 'text-success' : 'text-danger fw-bold' }}">{{ $tb['balanced'] ? 'In balance: total debits equal total credits.' : 'OUT OF BALANCE. This should never happen; please investigate.' }}</div></div>
@endsection
