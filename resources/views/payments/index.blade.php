@extends('layouts.app')
@section('title', 'Payments')
@section('content')
<div class="card">
    <div class="card-header d-flex flex-wrap gap-2 justify-content-between">
        <form class="row g-2 flex-grow-1" method="GET">
            <div class="col-md-3"><select name="payee" class="form-select form-select-sm"><option value="">All payees</option>
                @foreach ($payees as $group => $list)<optgroup label="{{ $group }}">@foreach ($list as $p)<option value="{{ $p['value'] }}" @selected(request('payee') === $p['value'])>{{ $p['name'] }}</option>@endforeach</optgroup>@endforeach</select></div>
            <div class="col-md-2"><select name="status" class="form-select form-select-sm"><option value="">Any status</option>@foreach (['draft', 'posted', 'cancelled'] as $s)<option @selected(request('status') === $s)>{{ $s }}</option>@endforeach</select></div>
            <div class="col-md-2"><input type="date" name="from" value="{{ request('from') }}" class="form-control form-control-sm"></div>
            <div class="col-md-2"><input type="date" name="to" value="{{ request('to') }}" class="form-control form-control-sm"></div>
            <div class="col-md-1"><button class="btn btn-sm btn-outline-secondary w-100">Go</button></div>
        </form>
        @can('payments.create')<a href="{{ route('payments.create') }}" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg"></i> New payment</a>@endcan
    </div>
    <div class="table-responsive"><table class="table table-hover align-middle mb-0">
        <thead class="table-light"><tr><th>No.</th><th>Date</th><th>Paid to</th><th>Method</th><th>From</th><th class="text-end">Amount</th><th class="text-end">On account</th><th>Status</th></tr></thead>
        <tbody>
        @forelse ($payments as $p)
            <tr class="{{ $p->status === 'cancelled' ? 'text-muted' : '' }}">
                <td><a href="{{ route('payments.show', $p) }}">{{ $p->payment_no }}</a></td><td>{{ $p->payment_date->format('d-M-Y') }}</td>
                <td>{{ $names[$p->payee_type . ':' . $p->payee_id] ?? '—' }} <small class="text-muted">{{ $p->payee_type }}</small></td>
                <td>{{ $p->method }}</td><td>{{ $p->account?->name }}</td><td class="text-end">{{ number_format((float) $p->amount, 2) }}</td>
                <td class="text-end">{{ $p->status === 'posted' ? number_format((float) $p->amount - (float) ($applied[$p->id] ?? 0), 2) : '' }}</td>
                <td><span class="badge text-bg-{{ ['draft' => 'warning', 'posted' => 'success', 'cancelled' => 'secondary'][$p->status] }}">{{ $p->status }}</span></td></tr>
        @empty<tr><td colspan="8" class="text-center text-muted py-4">No payments.</td></tr>@endforelse
        </tbody></table></div>
    <div class="card-footer">{{ $payments->links() }}</div>
</div>
@endsection
