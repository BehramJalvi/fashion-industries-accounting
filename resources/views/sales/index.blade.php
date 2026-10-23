@extends('layouts.app')
@section('title', 'Sales')
@section('content')
@php
    $m = fn ($c) => number_format($c / 100, 2);
    $badge = ['draft' => 'warning', 'unpaid' => 'danger', 'partial' => 'info', 'paid' => 'success', 'cancelled' => 'secondary'];
@endphp
<div class="card">
    <div class="card-header d-flex flex-wrap gap-2 justify-content-between">
        <form class="row g-2 flex-grow-1" method="GET">
            <div class="col-md-3"><input name="q" value="{{ request('q') }}" class="form-control form-control-sm" placeholder="Invoice no., buyer or OGP"></div>
            <div class="col-md-3"><select name="customer_id" class="form-select form-select-sm"><option value="">All customers</option>
                @foreach ($customers as $c)<option value="{{ $c->id }}" @selected(request('customer_id') == $c->id)>{{ $c->name }}</option>@endforeach</select></div>
            <div class="col-md-2"><select name="status" class="form-select form-select-sm"><option value="">Any status</option>@foreach (['draft', 'posted', 'cancelled'] as $s)<option @selected(request('status') === $s)>{{ $s }}</option>@endforeach</select></div>
            <div class="col-md-2"><input type="date" name="from" value="{{ request('from') }}" class="form-control form-control-sm"></div>
            <div class="col-md-2"><input type="date" name="to" value="{{ request('to') }}" class="form-control form-control-sm"></div>
            <div class="col-12 col-md-auto"><button class="btn btn-sm btn-outline-secondary">Filter</button></div>
        </form>
        @can('sales.create')<a href="{{ route('sales.create') }}" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg"></i> New invoice</a>@endcan
    </div>
    <div class="table-responsive"><table class="table table-hover align-middle mb-0">
        <thead class="table-light"><tr><th>Invoice</th><th>Date</th><th>Customer</th><th>OGP</th><th class="text-end">Total</th><th class="text-end">Received</th><th class="text-end">Balance</th><th>Status</th></tr></thead>
        <tbody>
        @forelse ($sales as $sale)
            @php
                $s = $summaries[$sale->id];
            @endphp
            <tr class="{{ $sale->status === 'cancelled' ? 'text-muted' : '' }}">
                <td><a href="{{ route('sales.show', $sale) }}">{{ $sale->hasInvoiceNumber() ? $sale->invoice_no : 'Draft' }}</a></td>
                <td>{{ $sale->sale_date->format('d-M-Y') }}</td>
                <td>{{ $sale->customer?->name }}</td>
                <td>{{ $sale->gate_pass_no }}</td>
                <td class="text-end">{{ number_format((float) $sale->total, 2) }}</td>
                <td class="text-end">{{ $sale->status === 'posted' ? $m($s['paid']) : '' }}</td>
                <td class="text-end">{{ $sale->status === 'posted' ? $m($s['balance']) : '' }}</td>
                <td><span class="badge text-bg-{{ $badge[$s['status']] }}">{{ $s['status'] }}</span></td>
            </tr>
        @empty<tr><td colspan="8" class="text-center text-muted py-4">No invoices.</td></tr>@endforelse
        </tbody></table></div>
    <div class="card-footer">{{ $sales->links() }}</div>
</div>
@endsection
