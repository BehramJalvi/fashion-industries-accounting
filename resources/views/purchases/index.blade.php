@extends('layouts.app')
@section('title', 'Purchases')
@section('content')
@php
    $m = fn ($c) => number_format($c / 100, 2);
    $badge = ['draft' => 'warning', 'unpaid' => 'danger', 'partial' => 'info', 'paid' => 'success', 'cancelled' => 'secondary'];
@endphp
<div class="card">
    <div class="card-header d-flex flex-wrap gap-2 justify-content-between">
        <form class="row g-2 flex-grow-1" method="GET">
            <div class="col-md-3"><input name="q" value="{{ request('q') }}" class="form-control form-control-sm" placeholder="PUR no. or supplier invoice"></div>
            <div class="col-md-3"><select name="supplier_id" class="form-select form-select-sm"><option value="">All suppliers</option>
                @foreach ($suppliers as $s)<option value="{{ $s->id }}" @selected(request('supplier_id') == $s->id)>{{ $s->name }}</option>@endforeach</select></div>
            <div class="col-md-2"><select name="status" class="form-select form-select-sm"><option value="">Any status</option>@foreach (['draft', 'posted', 'cancelled'] as $s)<option @selected(request('status') === $s)>{{ $s }}</option>@endforeach</select></div>
            <div class="col-md-2"><input type="date" name="from" value="{{ request('from') }}" class="form-control form-control-sm"></div>
            <div class="col-md-2"><input type="date" name="to" value="{{ request('to') }}" class="form-control form-control-sm"></div>
            <div class="col-12 col-md-auto"><button class="btn btn-sm btn-outline-secondary">Filter</button></div>
        </form>
        @can('purchases.create')<a href="{{ route('purchases.create') }}" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg"></i> New purchase</a>@endcan
    </div>
    <div class="table-responsive"><table class="table table-hover align-middle mb-0">
        <thead class="table-light"><tr><th>No.</th><th>Date</th><th>Supplier</th><th>Invoice</th><th class="text-end">Total</th><th class="text-end">Paid</th><th class="text-end">Balance</th><th>Status</th></tr></thead>
        <tbody>
        @forelse ($purchases as $p)
            @php
                $s = $summaries[$p->id];
            @endphp
            <tr class="{{ $p->status === 'cancelled' ? 'text-muted' : '' }}">
                <td><a href="{{ route('purchases.show', $p) }}">{{ $p->purchase_no }}</a></td>
                <td>{{ $p->purchase_date->format('d-M-Y') }}</td>
                <td>{{ $p->supplier?->name ?? 'Walk-in' }}</td>
                <td>{{ $p->supplier_ref }}</td>
                <td class="text-end">{{ number_format((float) $p->total, 2) }}</td>
                <td class="text-end">{{ in_array($p->status, ['posted']) ? $m($s['paid']) : '' }}</td>
                <td class="text-end">{{ $p->status === 'posted' ? $m($s['balance']) : '' }}</td>
                <td><span class="badge text-bg-{{ $badge[$s['status']] }}">{{ $s['status'] }}</span></td>
            </tr>
        @empty<tr><td colspan="8" class="text-center text-muted py-4">No purchases.</td></tr>@endforelse
        </tbody></table></div>
    <div class="card-footer">{{ $purchases->links() }}</div>
</div>
@endsection
