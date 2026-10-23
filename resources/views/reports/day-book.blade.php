@extends('layouts.app')
@section('title', 'Day Book')
@section('content')
<form method="GET" class="card card-body mb-3 no-print"><div class="row g-2 align-items-end">
    <div class="col-md-3"><label class="form-label small">From</label><input type="date" name="from" value="{{ $from }}" class="form-control form-control-sm"></div>
    <div class="col-md-3"><label class="form-label small">To</label><input type="date" name="to" value="{{ $to }}" class="form-control form-control-sm"></div>
    <div class="col-md-3"><label class="form-label small">Transaction type</label><select name="voucher_type" class="form-select form-select-sm"><option value="">All</option>@foreach ($voucherTypes as $t)<option @selected($voucher === $t)>{{ $t }}</option>@endforeach</select></div>
    <div class="col-md-2"><button class="btn btn-sm btn-outline-secondary w-100">Show</button></div>
</div></form>
@include('reports._toolbar', ['title' => 'Day Book', 'subtitle' => $from . ($to !== $from ? ' to ' . $to : '')])
<div class="row g-3">
    <div class="col-lg-9"><div class="card"><div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
        <thead class="table-light"><tr><th>Date</th><th>Entry</th><th>Type</th><th>Reference</th><th>Description</th><th class="text-end">Amount</th><th>Status</th></tr></thead><tbody>
        @forelse ($book['entries'] as $e)
            <tr class="{{ $e->status === 'cancelled' ? 'text-muted' : '' }}"><td>{{ $e->entry_date->format('d-M-Y') }}</td><td><a href="{{ route('journal.show', $e) }}">{{ $e->entry_no }}</a></td><td>{{ $e->voucher_type }}</td><td>{{ $e->reference }}</td>
                <td>{{ $e->description }}</td><td class="text-end">{{ number_format((float) $e->total_amount, 2) }}</td><td>{{ $e->status }}</td></tr>
        @empty<tr><td colspan="7" class="text-center text-muted py-4">No transactions.</td></tr>@endforelse
        </tbody></table></div></div></div>
    <div class="col-lg-3"><div class="card"><div class="card-header fw-semibold">By type</div><table class="table table-sm mb-0"><tbody>
        @foreach ($book['summary'] as $type => $s)<tr><td>{{ $type }}</td><td class="text-end">{{ $s['count'] }}</td><td class="text-end">{{ \App\Support\Money::display($s['amount']) }}</td></tr>@endforeach</tbody></table></div></div>
</div>
@endsection
