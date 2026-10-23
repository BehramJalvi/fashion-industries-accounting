@extends('layouts.app')
@section('title', 'Products')
@section('content')
<div class="card">
    <div class="card-header d-flex flex-wrap gap-2 justify-content-between">
        <form class="row g-2 flex-grow-1" method="GET">
            <div class="col-md-3"><input name="q" value="{{ request('q') }}" class="form-control form-control-sm" placeholder="Search name or code"></div>
            <div class="col-md-3"><select name="category_id" class="form-select form-select-sm"><option value="">All categories</option>
                @foreach ($categories as $c)<option value="{{ $c->id }}" @selected(request('category_id') == $c->id)>{{ $c->name }}</option>@endforeach</select></div>
            <div class="col-md-2"><select name="status" class="form-select form-select-sm"><option value="">Any status</option>
                <option value="active" @selected(request('status') === 'active')>Active</option><option value="inactive" @selected(request('status') === 'inactive')>Inactive</option></select></div>
            <div class="col-md-2 d-flex align-items-center"><div class="form-check"><input class="form-check-input" type="checkbox" name="low" value="1" id="low" @checked(request('low'))>
                <label class="form-check-label small" for="low">Low stock only</label></div></div>
            <div class="col-md-2"><button class="btn btn-sm btn-outline-secondary w-100">Filter</button></div>
        </form>
        @can('products.create')<a href="{{ route('products.create') }}" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg"></i> New product</a>@endcan
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light"><tr><th></th><th>Code</th><th>Product</th><th>Category</th><th class="text-end">Stock</th><th class="text-end">Avg cost</th><th class="text-end">Sell price</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @forelse ($products as $p)
                @php
                    $row = $last->get($p->id);
                @endphp
                @php
                    $qty = (float) ($row->balance_qty ?? 0);
                @endphp
                <tr>
                    <td style="width:48px">@if ($p->image_url)<img src="{{ $p->image_url }}" alt="" width="40" height="40" class="rounded object-fit-cover">@endif</td>
                    <td>{{ $p->code }}</td>
                    <td><a href="{{ route('inventory.ledger', ['product_id' => $p->id]) }}">{{ $p->name }}</a>
                        <span class="badge text-bg-light border">{{ str_replace('_', ' ', $p->product_type) }}</span></td>
                    <td>{{ $p->category?->name }}</td>
                    <td class="text-end">{{ rtrim(rtrim(number_format($qty, 3), '0'), '.') }} {{ $p->unit?->short_name ?? $p->unit?->name }}
                        @if ($p->is_active && $qty < (float) $p->min_stock)<span class="badge text-bg-warning">low</span>@endif</td>
                    <td class="text-end">{{ number_format((float) ($row->avg_cost_after ?? 0), 2) }}</td>
                    <td class="text-end">{{ number_format((float) $p->selling_price, 2) }}</td>
                    <td><span class="badge text-bg-{{ $p->is_active ? 'success' : 'secondary' }}">{{ $p->is_active ? 'Active' : 'Inactive' }}</span></td>
                    <td class="text-end text-nowrap">
                        @can('products.update')<a href="{{ route('products.edit', $p) }}" class="btn btn-sm btn-outline-primary">Edit</a>@endcan
                        @can('products.delete')
                            <form method="POST" action="{{ route('products.destroy', $p) }}" class="d-inline" onsubmit="return confirm('Delete {{ $p->name }}?')">
                                @csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger">Delete</button></form>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="9" class="text-center text-muted py-4">No products found.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="card-footer">{{ $products->links() }}</div>
</div>
@endsection
