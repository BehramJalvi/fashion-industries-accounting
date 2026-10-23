@extends('layouts.app')
@section('title', 'Sales Returns')
@section('content')
<div class="card">
    <div class="card-header d-flex flex-wrap gap-2 justify-content-between">
        <form class="row g-2 flex-grow-1" method="GET">
            <div class="col-md-4"><select name="customer_id" class="form-select form-select-sm"><option value="">All customers</option>
                @foreach ($customers as $c)<option value="{{ $c->id }}" @selected(request('customer_id') == $c->id)>{{ $c->name }}</option>@endforeach</select></div>
            <div class="col-md-2"><select name="status" class="form-select form-select-sm"><option value="">Any status</option>@foreach (['draft', 'posted', 'cancelled'] as $s)<option @selected(request('status') === $s)>{{ $s }}</option>@endforeach</select></div>
            <div class="col-md-2"><input type="date" name="from" value="{{ request('from') }}" class="form-control form-control-sm"></div>
            <div class="col-md-2"><input type="date" name="to" value="{{ request('to') }}" class="form-control form-control-sm"></div>
            <div class="col-md-2"><button class="btn btn-sm btn-outline-secondary w-100">Filter</button></div>
        </form>
        @can('sales_returns.create')<a href="{{ route('sales-returns.create') }}" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg"></i> New return</a>@endcan
    </div>
    <div class="table-responsive"><table class="table table-hover align-middle mb-0">
        <thead class="table-light"><tr><th>No.</th><th>Date</th><th>Invoice</th><th>Customer</th><th class="text-end">Value</th><th class="text-end">Refunded now</th><th>Status</th></tr></thead>
        <tbody>
        @forelse ($returns as $r)
            <tr class="{{ $r->status === 'cancelled' ? 'text-muted' : '' }}">
                <td><a href="{{ route('sales-returns.show', $r) }}">{{ $r->return_no }}</a></td><td>{{ $r->return_date->format('d-M-Y') }}</td>
                <td><a href="{{ route('sales.show', $r->sale_id) }}">{{ $r->sale?->invoice_no }}</a></td><td>{{ $r->customer?->name }}</td>
                <td class="text-end">{{ number_format((float) $r->total, 2) }}</td><td class="text-end">{{ (float) $r->refund_now ? number_format((float) $r->refund_now, 2) : '' }}</td>
                <td><span class="badge text-bg-{{ ['draft' => 'warning', 'posted' => 'success', 'cancelled' => 'secondary'][$r->status] }}">{{ $r->status }}</span></td></tr>
        @empty<tr><td colspan="7" class="text-center text-muted py-4">No sales returns.</td></tr>@endforelse
        </tbody></table></div>
    <div class="card-footer">{{ $returns->links() }}</div>
</div>
@endsection
