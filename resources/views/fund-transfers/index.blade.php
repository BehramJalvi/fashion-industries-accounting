@extends('layouts.app')
@section('title', 'Fund Transfers')
@section('content')
<div class="card">
    <div class="card-header d-flex flex-wrap gap-2 justify-content-between">
        <form class="d-flex gap-2" method="GET"><select name="status" class="form-select form-select-sm" onchange="this.form.submit()"><option value="">Any status</option>@foreach (['draft', 'posted', 'cancelled'] as $s)<option @selected(request('status') === $s)>{{ $s }}</option>@endforeach</select>
            <input type="date" name="from" value="{{ request('from') }}" class="form-control form-control-sm"><input type="date" name="to" value="{{ request('to') }}" class="form-control form-control-sm"><button class="btn btn-sm btn-outline-secondary">Go</button></form>
        @can('fund_transfers.create')<a href="{{ route('fund-transfers.create') }}" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg"></i> New transfer</a>@endcan
    </div>
    <div class="table-responsive"><table class="table table-hover align-middle mb-0">
        <thead class="table-light"><tr><th>No.</th><th>Date</th><th>From</th><th>To</th><th>Reference</th><th class="text-end">Amount</th><th>Status</th></tr></thead>
        <tbody>
        @forelse ($transfers as $t)
            <tr class="{{ $t->status === 'cancelled' ? 'text-muted' : '' }}"><td><a href="{{ route('fund-transfers.show', $t) }}">{{ $t->transfer_no }}</a></td><td>{{ $t->transfer_date->format('d-M-Y') }}</td>
                <td>{{ $t->fromAccount->name }}</td><td>{{ $t->toAccount->name }}</td><td>{{ $t->reference }}</td><td class="text-end">{{ number_format((float) $t->amount, 2) }}</td>
                <td><span class="badge text-bg-{{ ['draft' => 'warning', 'posted' => 'success', 'cancelled' => 'secondary'][$t->status] }}">{{ $t->status }}</span></td></tr>
        @empty<tr><td colspan="7" class="text-center text-muted py-4">No transfers.</td></tr>@endforelse
        </tbody></table></div>
    <div class="card-footer">{{ $transfers->links() }}</div>
</div>
@endsection
