@extends('layouts.app')
@section('title', 'Expenses')
@section('content')
<div class="card">
    <div class="card-header d-flex flex-wrap gap-2 justify-content-between">
        <form class="row g-2 flex-grow-1" method="GET">
            <div class="col-md-3"><input name="q" value="{{ request('q') }}" class="form-control form-control-sm" placeholder="Description or EXP no."></div>
            <div class="col-md-3"><select name="expense_account_id" class="form-select form-select-sm"><option value="">All expense types</option>
                @foreach ($accounts as $a)<option value="{{ $a->id }}" @selected(request('expense_account_id') == $a->id)>{{ $a->name }}</option>@endforeach</select></div>
            <div class="col-md-2"><select name="status" class="form-select form-select-sm"><option value="">Any status</option>@foreach (['draft', 'posted', 'cancelled'] as $s)<option @selected(request('status') === $s)>{{ $s }}</option>@endforeach</select></div>
            <div class="col-md-2"><input type="date" name="from" value="{{ request('from') }}" class="form-control form-control-sm"></div>
            <div class="col-md-2"><input type="date" name="to" value="{{ request('to') }}" class="form-control form-control-sm"></div>
        </form>
        @can('expenses.create')<a href="{{ route('expenses.create') }}" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg"></i> New expense</a>@endcan
    </div>
    <div class="table-responsive"><table class="table table-hover align-middle mb-0">
        <thead class="table-light"><tr><th>No.</th><th>Date</th><th>Type</th><th>Description</th><th>Paid</th><th class="text-end">Amount</th><th class="text-end">Tax</th><th>Status</th></tr></thead>
        <tbody>
        @forelse ($expenses as $e)
            <tr class="{{ $e->status === 'cancelled' ? 'text-muted' : '' }}">
                <td><a href="{{ route('expenses.show', $e) }}">{{ $e->expense_no }}</a></td><td>{{ $e->expense_date->format('d-M-Y') }}</td><td>{{ $e->expenseAccount?->name }}</td>
                <td>{{ $e->description }}</td><td>{{ $e->paidFromAccount?->name ?? 'On credit' }}</td>
                <td class="text-end">{{ number_format((float) $e->amount, 2) }}</td><td class="text-end">{{ (float) $e->tax_amount ? number_format((float) $e->tax_amount, 2) : '' }}</td>
                <td><span class="badge text-bg-{{ ['draft' => 'warning', 'posted' => 'success', 'cancelled' => 'secondary'][$e->status] }}">{{ $e->status }}</span></td></tr>
        @empty<tr><td colspan="8" class="text-center text-muted py-4">No expenses.</td></tr>@endforelse
        </tbody></table></div>
    <div class="card-footer">{{ $expenses->links() }}</div>
</div>
@endsection
