@extends('layouts.app')
@section('title', 'Inventory')
@section('content')
@unless ($reconciled)
    <div class="alert alert-warning">
        <strong>Inventory does not reconcile.</strong> Stock ledger value is {{ number_format($ledgerValue, 2) }} but the Inventory account (1200) shows {{ number_format($glValue, 2) }}.
        Difference {{ number_format($ledgerValue - $glValue, 2) }}. This should always be zero; please investigate before closing a period.
    </div>
@endunless

<div class="row g-3 mb-3">
    <div class="col-md-4"><div class="card"><div class="card-body"><div class="text-muted small">Stock value (weighted average)</div><div class="fs-4">PKR {{ number_format($totalValue, 2) }}</div></div></div></div>
    <div class="col-md-4"><div class="card"><div class="card-body"><div class="text-muted small">Inventory account (1200)</div><div class="fs-4">PKR {{ number_format($glValue, 2) }}</div></div></div></div>
    <div class="col-md-4"><div class="card"><div class="card-body"><div class="text-muted small">Control check</div>
        <div class="fs-4 text-{{ $reconciled ? 'success' : 'danger' }}"><i class="bi bi-{{ $reconciled ? 'check-circle' : 'exclamation-triangle' }}"></i> {{ $reconciled ? 'Reconciled' : 'Difference' }}</div></div></div></div>
</div>

<div class="card">
    <div class="card-header">
        <form class="row g-2" method="GET">
            <div class="col-md-4"><input name="q" value="{{ request('q') }}" class="form-control form-control-sm" placeholder="Search name or code"></div>
            <div class="col-md-3"><select name="category_id" class="form-select form-select-sm"><option value="">All categories</option>
                @foreach ($categories as $c)<option value="{{ $c->id }}" @selected(request('category_id') == $c->id)>{{ $c->name }}</option>@endforeach</select></div>
            <div class="col-md-3 d-flex align-items-center"><div class="form-check"><input class="form-check-input" type="checkbox" name="low" value="1" id="low" @checked(request('low'))><label class="form-check-label small" for="low">Low stock only</label></div></div>
            <div class="col-md-2"><button class="btn btn-sm btn-outline-secondary w-100">Filter</button></div>
        </form>
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead class="table-light"><tr><th>Code</th><th>Product</th><th>Category</th><th class="text-end">Quantity</th><th class="text-end">Min</th><th class="text-end">Avg cost</th><th class="text-end">Value</th><th></th></tr></thead>
            <tbody>
            @forelse ($rows as $p)
                <tr>
                    <td>{{ $p->code }}</td><td>{{ $p->name }}</td><td>{{ $p->category?->name }}</td>
                    <td class="text-end">{{ rtrim(rtrim(number_format($p->qty, 3), '0'), '.') ?: '0' }} {{ $p->unit?->short_name }}
                        @if ($p->low)<span class="badge text-bg-warning">low</span>@endif</td>
                    <td class="text-end">{{ rtrim(rtrim(number_format((float) $p->min_stock, 3), '0'), '.') ?: '0' }}</td>
                    <td class="text-end">{{ number_format($p->avg, 4) }}</td>
                    <td class="text-end">{{ number_format($p->value, 2) }}</td>
                    <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="{{ route('inventory.ledger', ['product_id' => $p->id]) }}">Ledger</a></td>
                </tr>
            @empty
                <tr><td colspan="8" class="text-center text-muted py-4">No products.</td></tr>
            @endforelse
            </tbody>
            <tfoot class="table-light fw-semibold"><tr><td colspan="6" class="text-end">Total</td><td class="text-end">{{ number_format($totalValue, 2) }}</td><td></td></tr></tfoot>
        </table>
    </div>
</div>
@endsection
