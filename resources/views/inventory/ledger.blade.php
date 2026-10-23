@extends('layouts.app')
@section('title', 'Inventory Ledger')
@section('content')
@php
    $fmt = fn ($n, $d = 3) => rtrim(rtrim(number_format((float) $n, $d), '0'), '.') ?: '0';
    $labels = [
        'opening' => 'Opening stock entered', 'purchase' => 'Purchases', 'purchase_return' => 'Purchase returns', 'sale' => 'Sales',
        'sale_return' => 'Sales returns', 'adjustment_in' => 'Adjustments (in)', 'adjustment_out' => 'Adjustments (out)',
        'production_consume' => 'Production used', 'production_output' => 'Production output', 'reversal' => 'Cancellations',
    ];
@endphp
<div class="card mb-3"><div class="card-body">
    <form class="row g-2" method="GET">
        <div class="col-md-5"><select name="product_id" class="form-select" onchange="this.form.submit()"><option value="">Select a product…</option>
            @foreach ($products as $p)<option value="{{ $p->id }}" @selected(request('product_id') == $p->id)>{{ $p->code }} · {{ $p->name }}</option>@endforeach</select></div>
        <div class="col-md-2"><input type="date" name="from" value="{{ request('from') }}" class="form-control"></div>
        <div class="col-md-2"><input type="date" name="to" value="{{ request('to') }}" class="form-control"></div>
        <div class="col-md-2"><button class="btn btn-outline-secondary w-100">Show</button></div>
    </form>
</div></div>

@if ($product)
    <div class="row g-3 mb-3">
        <div class="col-md-3"><div class="card"><div class="card-body"><div class="text-muted small">Opening</div><div class="fs-5">{{ $fmt($openingQty) }}</div><div class="small text-muted">PKR {{ number_format($openingValue, 2) }}</div></div></div></div>
        <div class="col-md-3"><div class="card"><div class="card-body"><div class="text-muted small">Closing</div><div class="fs-5">{{ $fmt($closing->balance_qty ?? 0) }}</div><div class="small text-muted">PKR {{ number_format((float) ($closing->balance_value ?? 0), 2) }}</div></div></div></div>
        <div class="col-md-6"><div class="card h-100"><div class="card-body p-2">
            <table class="table table-sm mb-0"><thead><tr><th>Movement</th><th class="text-end">In</th><th class="text-end">Out</th><th class="text-end">Cost</th></tr></thead><tbody>
            @forelse ($byType as $type => $t)
                <tr><td>{{ $labels[$type] ?? $type }}</td><td class="text-end">{{ $fmt($t['in']) }}</td><td class="text-end">{{ $fmt($t['out']) }}</td><td class="text-end">{{ number_format($t['cost'], 2) }}</td></tr>
            @empty<tr><td colspan="4" class="text-muted">No movements in this period.</td></tr>@endforelse
            </tbody></table></div></div></div>
    </div>

    <div class="card">
        <div class="card-header fw-semibold">{{ $product->code }} · {{ $product->name }}</div>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light"><tr><th>Date</th><th>Type</th><th>Source</th><th class="text-end">In</th><th class="text-end">Out</th><th class="text-end">Unit cost</th><th class="text-end">Balance</th><th class="text-end">Avg cost</th><th class="text-end">Value</th></tr></thead>
                <tbody>
                @forelse ($rows as $r)
                    <tr class="{{ $r->txn_type === 'reversal' ? 'table-secondary' : '' }}">
                        <td>{{ $r->txn_date->format('d-M-Y') }}</td>
                        <td>{{ $labels[$r->txn_type] ?? $r->txn_type }}</td>
                        <td>@if ($r->source_type === 'stock_adjustment' && Route::has('stock-adjustments.show'))
                                <a href="{{ route('stock-adjustments.show', $r->source_id) }}">Adjustment #{{ $r->source_id }}</a>
                            @elseif ($r->source_type){{ str_replace('_', ' ', $r->source_type) }} #{{ $r->source_id }}@endif</td>
                        <td class="text-end">{{ (float) $r->qty_in > 0 ? $fmt($r->qty_in) : '' }}</td>
                        <td class="text-end">{{ (float) $r->qty_out > 0 ? $fmt($r->qty_out) : '' }}</td>
                        <td class="text-end">{{ number_format((float) $r->unit_cost, 4) }}</td>
                        <td class="text-end">{{ $fmt($r->balance_qty) }}</td>
                        <td class="text-end">{{ number_format((float) $r->avg_cost_after, 4) }}</td>
                        <td class="text-end">{{ number_format((float) $r->balance_value, 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="text-center text-muted py-4">No movements.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endif
@endsection
