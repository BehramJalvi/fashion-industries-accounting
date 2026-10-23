@extends('layouts.app')
@section('title', 'Receipts')
@section('content')
<div class="card">
    <div class="card-header d-flex flex-wrap gap-2 justify-content-between">
        <form class="row g-2 flex-grow-1" method="GET">
            <div class="col-md-3"><select name="customer_id" class="form-select form-select-sm"><option value="">All customers</option>
                @foreach ($customers as $c)<option value="{{ $c->id }}" @selected(request('customer_id') == $c->id)>{{ $c->name }}</option>@endforeach</select></div>
            <div class="col-md-2"><select name="method" class="form-select form-select-sm"><option value="">Any method</option>@foreach (['cash', 'bank', 'cheque', 'online'] as $m)<option @selected(request('method') === $m)>{{ $m }}</option>@endforeach</select></div>
            <div class="col-md-2"><select name="status" class="form-select form-select-sm"><option value="">Any status</option>@foreach (['draft', 'posted', 'cancelled'] as $s)<option @selected(request('status') === $s)>{{ $s }}</option>@endforeach</select></div>
            <div class="col-md-2"><input type="date" name="from" value="{{ request('from') }}" class="form-control form-control-sm"></div>
            <div class="col-md-2"><input type="date" name="to" value="{{ request('to') }}" class="form-control form-control-sm"></div>
            <div class="col-md-1"><button class="btn btn-sm btn-outline-secondary w-100">Go</button></div>
        </form>
        @can('receipts.create')<a href="{{ route('receipts.create') }}" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg"></i> New receipt</a>@endcan
    </div>
    <div class="table-responsive"><table class="table table-hover align-middle mb-0">
        <thead class="table-light"><tr><th>No.</th><th>Date</th><th>Customer</th><th>Method</th><th>Into</th><th class="text-end">Amount</th><th class="text-end">On account</th><th>Status</th></tr></thead>
        <tbody>
        @forelse ($receipts as $r)
            <tr class="{{ $r->status === 'cancelled' ? 'text-muted' : '' }}">
                <td><a href="{{ route('receipts.show', $r) }}">{{ $r->receipt_no }}</a></td><td>{{ $r->receipt_date->format('d-M-Y') }}</td><td>{{ $r->customer?->name }}</td>
                <td>{{ $r->method }}</td><td>{{ $r->account?->name }}</td><td class="text-end">{{ number_format((float) $r->amount, 2) }}</td>
                <td class="text-end">{{ $r->status === 'posted' ? number_format((float) $r->amount - (float) ($applied[$r->id] ?? 0), 2) : '' }}</td>
                <td><span class="badge text-bg-{{ ['draft' => 'warning', 'posted' => 'success', 'cancelled' => 'secondary'][$r->status] }}">{{ $r->status }}</span></td></tr>
        @empty<tr><td colspan="8" class="text-center text-muted py-4">No receipts.</td></tr>@endforelse
        </tbody></table></div>
    <div class="card-footer">{{ $receipts->links() }}</div>
</div>
@endsection
