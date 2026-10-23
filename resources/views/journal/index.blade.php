@extends('layouts.app')
@section('title', 'Journal Entries')
@section('content')
<div class="card">
    <div class="card-header d-flex flex-wrap gap-2 justify-content-between">
        <form class="row g-2 flex-grow-1" method="GET">
            <div class="col-md-3"><input name="q" value="{{ request('q') }}" class="form-control form-control-sm" placeholder="Entry no, reference, description"></div>
            <div class="col-md-2"><input type="date" name="from" value="{{ request('from') }}" class="form-control form-control-sm"></div>
            <div class="col-md-2"><input type="date" name="to" value="{{ request('to') }}" class="form-control form-control-sm"></div>
            <div class="col-md-2"><select name="voucher_type" class="form-select form-select-sm"><option value="">All types</option>
                @foreach ($voucherTypes as $t)<option @selected(request('voucher_type') === $t)>{{ $t }}</option>@endforeach</select></div>
            <div class="col-md-2"><select name="status" class="form-select form-select-sm"><option value="">Any status</option>
                @foreach (['posted', 'cancelled'] as $s)<option @selected(request('status') === $s)>{{ $s }}</option>@endforeach</select></div>
            <div class="col-md-1"><button class="btn btn-sm btn-outline-secondary w-100">Go</button></div>
        </form>
        @can('journal.create')@can('journal.post')<a href="{{ route('journal.create') }}" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg"></i> New entry</a>@endcan @endcan
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead class="table-light"><tr><th>Entry</th><th>Date</th><th>Type</th><th>Reference</th><th>Description</th><th class="text-end">Amount</th><th>Status</th></tr></thead>
            <tbody>
            @forelse ($entries as $e)
                <tr>
                    <td><a href="{{ route('journal.show', $e) }}">{{ $e->entry_no }}</a></td>
                    <td>{{ $e->entry_date->format('d-M-Y') }}</td>
                    <td><span class="badge text-bg-light border">{{ $e->voucher_type }}</span></td>
                    <td>{{ $e->reference }}</td>
                    <td class="text-truncate" style="max-width:340px">{{ $e->description }}</td>
                    <td class="text-end">{{ number_format((float) $e->total_amount, 2) }}</td>
                    <td><span class="badge text-bg-{{ $e->status === 'posted' ? 'success' : 'secondary' }}">{{ $e->status }}</span></td>
                </tr>
            @empty
                <tr><td colspan="7" class="text-center text-muted py-4">No journal entries.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="card-footer">{{ $entries->links() }}</div>
</div>
@endsection
