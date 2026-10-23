@extends('layouts.app')
@section('title', $title)
@section('content')
<form method="GET" class="card card-body mb-3 no-print"><div class="row g-2 align-items-end">
    <div class="col-md-3"><label class="form-label small">As at</label><input type="date" name="to" value="{{ $to }}" class="form-control form-control-sm"></div>
    <div class="col-md-2"><button class="btn btn-sm btn-outline-secondary w-100">Show</button></div>
    <div class="col-md-7 text-end">@foreach (['customers' => 'Customers', 'suppliers' => 'Suppliers', 'employees' => 'Employees', 'owners' => 'Owners'] as $k => $l)<a class="btn btn-sm btn-{{ $k === $type ? 'primary' : 'outline-secondary' }}" href="{{ route('reports.balances', $k) }}">{{ $l }}</a> @endforeach</div>
</div></form>
@include('reports._toolbar', ['title' => $title, 'subtitle' => 'As at ' . $to])
<div class="card"><table class="table table-sm table-hover align-middle mb-0"><thead class="table-light"><tr><th>Name</th><th class="text-end">Balance (PKR)</th><th>Meaning</th></tr></thead><tbody>
    @forelse ($rows as $r)<tr><td><a href="{{ route($routeLedger, $r['id']) }}">{{ $r['name'] }}</a></td><td class="text-end {{ $r['balance'] < 0 ? 'text-danger' : '' }}">{{ \App\Support\Money::display(abs($r['balance'])) }}</td><td>{{ $r['balance'] >= 0 ? $positive : $negative }}</td></tr>
    @empty<tr><td colspan="3" class="text-center text-muted py-4">No balances.</td></tr>@endforelse
    </tbody><tfoot class="table-light fw-bold"><tr><td>Net total</td><td class="text-end">{{ \App\Support\Money::display($total) }}</td><td></td></tr></tfoot></table></div>
@endsection
