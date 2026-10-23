@extends('layouts.app')
@section('title', 'Settings')
@section('content')
@php
    $manage = auth()->user()->can('settings.manage');
    $v = fn ($key, $default = null) => old($key, $values[$key] ?? $default);
@endphp
<form method="POST" action="{{ route('settings.update') }}" enctype="multipart/form-data">
    @csrf @method('PUT')
    <fieldset @disabled(! $manage)>

    <div class="card mb-3"><div class="card-header fw-semibold">Company</div><div class="card-body row g-3">
        <div class="col-md-6"><label class="form-label">Business name</label><input name="company_name" class="form-control" value="{{ $v('company_name') }}" required maxlength="150"></div>
        <div class="col-md-6"><label class="form-label">Tagline (printed under the logo)</label><input name="company_tagline" class="form-control" value="{{ $v('company_tagline') }}" maxlength="200"></div>
        <div class="col-12"><label class="form-label">Address printed on invoices</label><textarea name="company_address" rows="2" class="form-control" maxlength="500">{{ $v('company_address') }}</textarea></div>
        <div class="col-md-3"><label class="form-label">Phone</label><input name="company_phone" class="form-control" value="{{ $v('company_phone') }}" maxlength="60"></div>
        <div class="col-md-3"><label class="form-label">Email</label><input type="email" name="company_email" class="form-control" value="{{ $v('company_email') }}" maxlength="150"></div>
        <div class="col-md-3"><label class="form-label">NTN</label><input name="company_ntn" class="form-control" value="{{ $v('company_ntn') }}" maxlength="30"></div>
        <div class="col-md-3"><label class="form-label">Sales tax reg. no. (STRN)</label><input name="company_strn" class="form-control" value="{{ $v('company_strn') }}" maxlength="30"></div>
        <div class="col-md-6">
            <label class="form-label">Logo <small class="text-muted">(PNG / JPG / WebP, max 1 MB)</small></label>
            @if ($co['logoUrl'])
                <div class="mb-2"><img src="{{ $co['logoUrl'] }}" alt="Logo" style="max-height:70px" class="border rounded p-1 bg-white"></div>
                <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="remove_logo" value="1" id="rm"><label class="form-check-label" for="rm">Remove current logo</label></div>
            @endif
            <input type="file" name="logo" accept="image/png,image/jpeg,image/webp" class="form-control">
        </div>
    </div></div>

    <div class="card mb-3"><div class="card-header fw-semibold">Invoice</div><div class="card-body row g-3">
        <div class="col-md-4"><label class="form-label">Invoice title</label><input name="invoice_title" class="form-control" value="{{ $v('invoice_title', 'SALES TAX INVOICE') }}" required maxlength="60"></div>
        <div class="col-md-2"><label class="form-label">Minimum table rows</label><input name="invoice_min_rows" class="form-control text-end" inputmode="numeric" value="{{ $v('invoice_min_rows', 10) }}" required></div>
        <div class="col-md-6 d-flex align-items-end"><div class="form-check"><input type="hidden" name="invoice_show_payment" value="0">
            <input class="form-check-input" type="checkbox" name="invoice_show_payment" value="1" id="sp" @checked($v('invoice_show_payment', true))><label class="form-check-label" for="sp">Show "Received" and "Balance" under the totals when part of the invoice was paid at the sale</label></div></div>
        <div class="col-12"><label class="form-label">Footer line</label><input name="invoice_footer" class="form-control" value="{{ $v('invoice_footer') }}" maxlength="300"></div>
    </div></div>

    <div class="card mb-3"><div class="card-header fw-semibold">Invoice numbering</div><div class="card-body row g-3">
        <div class="col-md-3"><label class="form-label">Prefix <small class="text-muted">(blank = plain number)</small></label><input name="sale_prefix" class="form-control" value="{{ old('sale_prefix', $sale->prefix) }}" maxlength="20"></div>
        <div class="col-md-3"><label class="form-label">Next invoice number</label><input name="sale_next_number" class="form-control text-end" inputmode="numeric" value="{{ old('sale_next_number', $sale->next_number) }}" required></div>
        <div class="col-md-3"><label class="form-label">Minimum digits</label><input name="sale_padding" class="form-control text-end" inputmode="numeric" value="{{ old('sale_padding', $sale->padding) }}" required></div>
        <div class="col-md-3 d-flex align-items-end text-muted small">Numbers are assigned when an invoice is posted. The next number must be higher than any already used.</div>
    </div></div>

    <div class="card mb-3"><div class="card-header fw-semibold">Tax and stock</div><div class="card-body row g-3">
        <div class="col-md-3"><label class="form-label">Default sales tax rate % <small class="text-muted">(new customers)</small></label><input name="default_sales_tax_rate" class="form-control text-end" inputmode="decimal" value="{{ $v('default_sales_tax_rate', 18) }}" required></div>
        <div class="col-md-9 d-flex align-items-end"><div class="form-check"><input type="hidden" name="allow_negative_stock" value="0">
            <input class="form-check-input" type="checkbox" name="allow_negative_stock" value="1" id="neg" @checked($v('allow_negative_stock', false))><label class="form-check-label" for="neg">Allow selling more than the stock on hand (stock goes negative)</label></div></div>
    </div></div>

    </fieldset>
    @if ($manage)<button class="btn btn-primary">Save settings</button>@else<p class="text-muted">Only an administrator can change settings.</p>@endif
</form>

<div class="card mt-4"><div class="card-header fw-semibold">Other document numbers</div>
    <table class="table table-sm mb-0"><thead class="table-light"><tr><th>Document</th><th>Prefix</th><th class="text-end">Next number</th></tr></thead><tbody>
    @foreach ($others as $s)<tr><td>{{ str_replace('_', ' ', $s->doc_type) }}</td><td>{{ $s->prefix }}</td><td class="text-end">{{ $s->next_number }}</td></tr>@endforeach
    </tbody></table>
</div>
@endsection
