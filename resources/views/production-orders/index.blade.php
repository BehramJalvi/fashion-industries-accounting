@extends('layouts.app')
@section('title', 'Production')
@section('content')
<div class="card">
    <div class="card-header d-flex flex-wrap gap-2 justify-content-between">
        <form class="row g-2 flex-grow-1" method="GET">
            <div class="col-md-3"><select name="status" class="form-select form-select-sm"><option value="">Any status</option>
                @foreach (['draft', 'posted', 'cancelled'] as $status)<option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>@endforeach</select></div>
            <div class="col-md-3"><input type="date" name="from" value="{{ request('from') }}" class="form-control form-control-sm"></div>
            <div class="col-md-3"><input type="date" name="to" value="{{ request('to') }}" class="form-control form-control-sm"></div>
            <div class="col-12 col-md-auto"><button class="btn btn-sm btn-outline-secondary">Filter</button></div>
        </form>
        @can('production_orders.create')<a href="{{ route('production-orders.create') }}" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg"></i> New production</a>@endcan
    </div>
    <div class="table-responsive"><table class="table table-hover align-middle mb-0">
        <thead class="table-light"><tr><th>No.</th><th>Date</th><th>Processor</th><th>Finished product</th><th class="text-end">Output qty</th><th class="text-end">Processing fee</th><th>Status</th></tr></thead>
        <tbody>
        @forelse ($orders as $order)
            <tr class="{{ $order->status === 'cancelled' ? 'text-muted' : '' }}">
                <td><a href="{{ route('production-orders.show', $order) }}">{{ $order->order_no }}</a></td>
                <td>{{ $order->order_date->format('d-M-Y') }}</td>
                <td>{{ $order->processor?->name ?? '—' }}</td>
                <td>
                    @forelse ($order->outputs as $output)
                        <div>{{ $output->product?->name ?? '—' }}</div>
                    @empty
                        —
                    @endforelse
                </td>
                <td class="text-end">
                    @forelse ($order->outputs as $output)
                        <div>{{ number_format((float) $output->qty, 3) }} {{ $output->product?->unit?->short_name }}</div>
                    @empty
                        —
                    @endforelse
                </td>
                <td class="text-end">{{ number_format((float) $order->processing_fee, 2) }}</td>
                <td><span class="badge text-bg-{{ ['draft' => 'warning', 'posted' => 'success', 'cancelled' => 'secondary'][$order->status] }}">{{ $order->status }}</span></td>
            </tr>
        @empty<tr><td colspan="7" class="text-center text-muted py-4">No production transactions.</td></tr>@endforelse
        </tbody>
    </table></div>
    <div class="card-footer">{{ $orders->links() }}</div>
</div>
@endsection
