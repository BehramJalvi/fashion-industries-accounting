@extends('layouts.app')
@section('title', 'Owner Capital & Drawings')
@section('content')
<div class="card">
    <div class="card-header d-flex flex-wrap gap-2 justify-content-between">
        <form class="row g-2 flex-grow-1" method="GET">
            <div class="col-md-3"><select name="owner_id" class="form-select form-select-sm"><option value="">All owners</option>
                @foreach ($owners as $o)<option value="{{ $o->id }}" @selected(request('owner_id') == $o->id)>{{ $o->name }}</option>@endforeach</select></div>
            <div class="col-md-2"><select name="txn_type" class="form-select form-select-sm"><option value="">Capital & drawings</option>
                <option value="capital" @selected(request('txn_type') === 'capital')>Capital</option><option value="drawing" @selected(request('txn_type') === 'drawing')>Drawings</option></select></div>
            <div class="col-md-2"><select name="status" class="form-select form-select-sm"><option value="">Any status</option>@foreach (['draft', 'posted', 'cancelled'] as $s)<option @selected(request('status') === $s)>{{ $s }}</option>@endforeach</select></div>
            <div class="col-md-2"><input type="date" name="from" value="{{ request('from') }}" class="form-control form-control-sm"></div>
            <div class="col-md-2"><input type="date" name="to" value="{{ request('to') }}" class="form-control form-control-sm"></div>
            <div class="col-md-1"><button class="btn btn-sm btn-outline-secondary w-100">Go</button></div>
        </form>
        @can('owner_transactions.create')<a href="{{ route('owner-transactions.create') }}" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg"></i> New</a>@endcan
    </div>
    <div class="table-responsive"><table class="table table-hover align-middle mb-0">
        <thead class="table-light"><tr><th>No.</th><th>Date</th><th>Owner</th><th>Type</th><th>Cash / Bank</th><th class="text-end">Amount</th><th>Status</th></tr></thead>
        <tbody>
        @forelse ($transactions as $t)
            <tr><td><a href="{{ route('owner-transactions.show', $t) }}">{{ $t->txn_no }}</a></td><td>{{ $t->txn_date->format('d-M-Y') }}</td><td>{{ $t->owner->name }}</td>
                <td>{{ $t->txn_type === 'capital' ? 'Capital introduced' : 'Drawings' }}</td><td>{{ $t->account->name }}</td>
                <td class="text-end">{{ number_format((float) $t->amount, 2) }}</td>
                <td><span class="badge text-bg-{{ ['draft' => 'warning', 'posted' => 'success', 'cancelled' => 'secondary'][$t->status] }}">{{ $t->status }}</span></td></tr>
        @empty<tr><td colspan="7" class="text-center text-muted py-4">Nothing recorded.</td></tr>@endforelse
        </tbody></table></div>
    <div class="card-footer">{{ $transactions->links() }}</div>
</div>
@endsection
