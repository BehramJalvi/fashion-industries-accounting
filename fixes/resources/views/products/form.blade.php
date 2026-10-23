@extends('layouts.app')
@php
    $editing = $product->exists;
@endphp
@section('title', $editing ? 'Edit product' : 'New product')
@section('content')
<form method="POST" enctype="multipart/form-data" action="{{ $editing ? route('products.update', $product) : route('products.store') }}">
    @csrf
    @if ($editing) @method('PUT') @endif
    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card"><div class="card-body row g-3">
                <div class="col-md-4"><label class="form-label">Code</label>
                    <input name="code" class="form-control" value="{{ old('code', $product->code) }}" maxlength="30" placeholder="{{ $editing ? '' : 'Auto if blank' }}"></div>
                <div class="col-md-8"><label class="form-label">Name</label>
                    <input name="name" class="form-control" value="{{ old('name', $product->name) }}" required maxlength="150"></div>
                <div class="col-md-4"><label class="form-label">Category</label>
                    <select name="category_id" class="form-select"><option value="">—</option>
                        @foreach ($categories as $c)<option value="{{ $c->id }}" @selected(old('category_id', $product->category_id) == $c->id)>{{ $c->name }}</option>@endforeach</select></div>
                <div class="col-md-4"><label class="form-label">Unit</label>
                    <select name="unit_id" class="form-select" required>
                        @foreach ($units as $u)<option value="{{ $u->id }}" @selected(old('unit_id', $product->unit_id) == $u->id)>{{ $u->name }}</option>@endforeach</select></div>
                <div class="col-md-4"><label class="form-label">Type</label>
                    <select name="product_type" class="form-select">
                        @foreach (['raw_material' => 'Raw material', 'finished_good' => 'Finished good', 'trading' => 'Trading item'] as $v => $l)
                            <option value="{{ $v }}" @selected(old('product_type', $product->product_type) === $v)>{{ $l }}</option>@endforeach</select></div>
                <div class="col-md-3"><label class="form-label">Purchase price <small class="text-muted">(default)</small></label>
                    <input name="purchase_price" class="form-control text-end" inputmode="decimal" value="{{ old('purchase_price', $product->purchase_price ?? 0) }}" required></div>
                <div class="col-md-3"><label class="form-label">Selling price</label>
                    <input name="selling_price" class="form-control text-end" inputmode="decimal" value="{{ old('selling_price', $product->selling_price ?? 0) }}" required></div>
                <div class="col-md-3"><label class="form-label">Tax rate % <small class="text-muted">(optional)</small></label>
                    <input name="default_tax_rate" class="form-control text-end" inputmode="decimal" value="{{ old('default_tax_rate', $product->default_tax_rate) }}"></div>
                <div class="col-md-3"><label class="form-label">Minimum stock</label>
                    <input name="min_stock" class="form-control text-end" inputmode="decimal" value="{{ old('min_stock', $product->min_stock ?? 0) }}"></div>
                <div class="col-12"><label class="form-label">Description</label>
                    <textarea name="description" rows="2" class="form-control" maxlength="2000">{{ old('description', $product->description) }}</textarea></div>
                <div class="col-12"><div class="form-check">
                    <input type="hidden" name="is_active" value="0">
                    <input class="form-check-input" type="checkbox" name="is_active" value="1" id="active" @checked(old('is_active', $product->is_active))>
                    <label class="form-check-label" for="active">Active</label></div></div>
            </div></div>
        </div>

        <div class="col-lg-4">
            <div class="card mb-3"><div class="card-body">
                <label class="form-label">Image <small class="text-muted">(JPG/PNG/WebP, max 2 MB)</small></label>
                @if ($product->image_url)
                    <img src="{{ $product->image_url }}" alt="" class="img-thumbnail mb-2 d-block" style="max-height:120px">
                    <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="remove_image" value="1" id="rm"><label class="form-check-label" for="rm">Remove image</label></div>
                @endif
                <input type="file" name="image" accept="image/png,image/jpeg,image/webp" class="form-control">
            </div></div>

            @unless ($editing)
                <div class="card"><div class="card-body">
                    <h6>Opening stock <small class="text-muted">(optional)</small></h6>
                    <p class="small text-muted">Posts Dr Inventory / Cr Opening Balance Equity. Later changes go through a stock adjustment.</p>
                    <div class="mb-2"><label class="form-label">Quantity</label><input name="opening_qty" class="form-control text-end" inputmode="decimal" value="{{ old('opening_qty') }}"></div>
                    <div class="mb-2"><label class="form-label">Unit cost</label><input name="opening_unit_cost" class="form-control text-end" inputmode="decimal" value="{{ old('opening_unit_cost') }}"></div>
                    <div><label class="form-label">As of date</label><input type="date" name="opening_date" class="form-control" value="{{ old('opening_date', now()->toDateString()) }}"></div>
                </div></div>
            @endunless
        </div>
    </div>
    <div class="mt-3">
        <a href="{{ route('products.index') }}" class="btn btn-light">Cancel</a>
        <button class="btn btn-primary">{{ $editing ? 'Save changes' : 'Create product' }}</button>
    </div>
</form>
@endsection
