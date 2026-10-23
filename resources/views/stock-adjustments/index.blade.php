@extends('layouts.app')
@section('title', 'Stock Adjustments')
@section('content')
<div class="card">
    <div class="card-header d-flex justify-content-between">
        <form method="GET" class="d-flex gap-2"><select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
            <option value="">All statuses</option>@foreach (['draft', 'posted', 'cancelled'] as $s)<option @selected(request('status') === $s)>{{ $s }}</option>@endforeach</select></form>
        @can('stock_adjustments.create')<a href="{{ route('stock-adjustments.create') }}" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg"></i> New adjustment</a>@endcan
    </div>
    <div class="table-responsive"><table class="table table-hover align-middle mb-0">
        <thead class="table-light"><tr><th>No.</th><th>Date</th><th>Reason</th><th>Items</th><th>Status</th></tr></thead>
        <tbody>
        @forelse ($adjustments as $a)
            <tr><td><a href="{{ route('stock-adjustments.show', $a) }}">{{ $a->adjustment_no }}</a></td><td>{{ $a->adjustment_date->format('d-M-Y') }}</td><td>{{ $a->reason }}</td><td>{{ $a->items_count }}</td>
                <td><span class="badge text-bg-{{ ['draft' => 'warning', 'posted' => 'success', 'cancelled' => 'secondary'][$a->status] }}">{{ $a->status }}</span></td></tr>
        @empty<tr><td colspan="5" class="text-center text-muted py-4">No stock adjustments.</td></tr>@endforelse
        </tbody></table></div>
    <div class="card-footer">{{ $adjustments->links() }}</div>
</div>
@endsection
