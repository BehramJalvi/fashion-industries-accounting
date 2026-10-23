@extends('layouts.app')
@php
    $editing = $sale->exists;
@endphp
@section('title', $editing ? 'Edit draft invoice' : 'New sales invoice')
@section('content')
@php
    $trim = fn ($n) => rtrim(rtrim((string) $n, '0'), '.');
    $rows = old('items', $editing
        ? $sale->items->map(fn ($i) => ['product_id' => $i->product_id, 'description' => $i->description, 'qty' => $trim($i->qty), 'unit_price' => $trim($i->unit_price), 'discount' => (float) $i->discount ?: '', 'tax_rate' => $trim($i->tax_rate)])->all()
        : [[]]);
@endphp
<form method="POST" action="{{ $editing ? route('sales.update', $sale) : route('sales.store') }}">
    @csrf
    @if ($editing) @method('PUT') @endif

    <div class="card mb-3"><div class="card-body row g-3">
        <div class="col-md-5"><label class="form-label">Customer</label>
            <select name="customer_id" id="customer" class="form-select" required><option value="">Select customer…</option>
                @foreach ($customers as $c)<option value="{{ $c->id }}" data-tax="{{ $c->effectiveTaxRate() }}" @selected(old('customer_id', $sale->customer_id) == $c->id)>{{ $c->name }}{{ $c->is_tax_exempt ? ' (tax exempt)' : '' }}</option>@endforeach</select></div>
        <div class="col-md-2"><label class="form-label">Date</label>
            <input type="date" name="sale_date" class="form-control" value="{{ old('sale_date', $sale->sale_date?->toDateString() ?? now()->toDateString()) }}" required></div>
        <div class="col-md-2"><label class="form-label">Due date</label>
            <input type="date" name="due_date" class="form-control" value="{{ old('due_date', $sale->due_date?->toDateString()) }}"></div>
        <div class="col-md-3"><label class="form-label">OGP / gate pass no.</label>
            <input name="gate_pass_no" class="form-control" value="{{ old('gate_pass_no', $sale->gate_pass_no) }}" maxlength="30"></div>
        <div class="col-12"><label class="form-label">Notes</label><input name="notes" class="form-control" value="{{ old('notes', $sale->notes) }}" maxlength="1000"></div>
    </div></div>

    <div class="card mb-3">
        <table class="table align-middle mb-0" id="items">
            <thead class="table-light"><tr><th>Product / description</th><th style="width:110px">Qty</th><th style="width:120px">Rate</th><th style="width:100px">Discount</th><th style="width:100px">Tax %</th><th style="width:130px" class="text-end">Line total</th><th style="width:40px"></th></tr></thead>
            <tbody>
            @foreach ($rows as $i => $row)
                <tr class="item">
                    <td><select name="items[{{ $i }}][product_id]" class="form-select form-select-sm prod mb-1" required><option value="">Select product…</option>
                        @foreach ($products as $p)<option value="{{ $p->id }}" data-price="{{ $trim($p->selling_price) }}" data-stock="{{ $trim($stock[$p->id] ?? 0) ?: '0' }}" @selected(($row['product_id'] ?? null) == $p->id)>{{ $p->code }} · {{ $p->name }}</option>@endforeach</select>
                        <input name="items[{{ $i }}][description]" class="form-control form-control-sm" placeholder="Invoice description (optional)" value="{{ $row['description'] ?? '' }}" maxlength="200"></td>
                    <td><input name="items[{{ $i }}][qty]" class="form-control form-control-sm text-end qty" inputmode="decimal" value="{{ $row['qty'] ?? '' }}" required><div class="small text-muted stock"></div></td>
                    <td><input name="items[{{ $i }}][unit_price]" class="form-control form-control-sm text-end price" inputmode="decimal" value="{{ $row['unit_price'] ?? '' }}" required></td>
                    <td><input name="items[{{ $i }}][discount]" class="form-control form-control-sm text-end disc" inputmode="decimal" value="{{ $row['discount'] ?? '' }}"></td>
                    <td><input name="items[{{ $i }}][tax_rate]" class="form-control form-control-sm text-end rate" inputmode="decimal" value="{{ $row['tax_rate'] ?? '' }}" placeholder="customer rate"></td>
                    <td class="text-end line">0.00</td>
                    <td><button type="button" class="btn btn-sm btn-outline-danger remove">&times;</button></td>
                </tr>
            @endforeach
            </tbody>
            <tfoot class="table-light">
                <tr><td colspan="7"><button type="button" class="btn btn-sm btn-outline-secondary" id="add"><i class="bi bi-plus"></i> Add line</button>
                    <span class="small text-muted ms-2">Leave Tax % blank to use the customer's rate; type 0 for a tax-free line.</span></td></tr>
                <tr><td colspan="5" class="text-end">Value ex-tax (before discount)</td><td class="text-end" id="tSub">0.00</td><td></td></tr>
                <tr><td colspan="5" class="text-end">Discount</td><td class="text-end" id="tDisc">0.00</td><td></td></tr>
                <tr><td colspan="5" class="text-end">Sales tax</td><td class="text-end" id="tTax">0.00</td><td></td></tr>
                <tr class="fw-bold"><td colspan="5" class="text-end">Total (incl. tax)</td><td class="text-end" id="tTotal">0.00</td><td></td></tr>
            </tfoot>
        </table>
    </div>

    <div class="card mb-3"><div class="card-body row g-3">
        <div class="col-md-4"><label class="form-label">Received now (PKR)</label>
            <input name="received_now" id="received" class="form-control text-end" inputmode="decimal" value="{{ old('received_now', (float) $sale->received_now ?: '') }}" placeholder="0 = on credit"></div>
        <div class="col-md-5"><label class="form-label">Received into</label>
            <select name="received_into_account_id" class="form-select"><option value="">—</option>
                @foreach ($accounts as $a)<option value="{{ $a->id }}" @selected(old('received_into_account_id', $sale->received_into_account_id) == $a->id)>{{ $a->code }} · {{ $a->name }}</option>@endforeach</select></div>
        <div class="col-md-3"><label class="form-label">Remaining receivable</label><div class="form-control-plaintext fw-semibold text-end" id="remaining">0.00</div></div>
        <div class="col-12 small text-muted">Stock is checked and costed when the invoice is posted. The invoice number is assigned at posting.</div>
    </div></div>

    <a href="{{ route('sales.index') }}" class="btn btn-light">Cancel</a>
    <button name="action" value="draft" class="btn btn-outline-primary">Save as draft</button>
    @can('sales.post')<button name="action" value="post" class="btn btn-primary">Save &amp; post</button>@endcan
</form>
@endsection

@push('scripts')
<script>
(function () {
    const $ = id => document.getElementById(id);
    const body = document.querySelector('#items tbody');
    const customer = $('customer');
    const num = v => parseFloat(v) || 0;
    const fmt = c => (c / 100).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const defaultRate = () => num(customer.selectedOptions[0]?.dataset.tax);

    function recalc() {
        let gross = 0, disc = 0, tax = 0;
        const dr = defaultRate();
        body.querySelectorAll('.item').forEach(r => {
            const rateField = r.querySelector('.rate');
            rateField.placeholder = dr + '% (customer)';
            const rate = rateField.value.trim() === '' ? dr : num(rateField.value);
            const g = Math.round(num(r.querySelector('.qty').value) * num(r.querySelector('.price').value) * 100);
            const d = Math.round(num(r.querySelector('.disc').value) * 100);
            const net = g - d, t = Math.round(net * rate / 100);
            r.querySelector('.line').textContent = fmt(net + t);
            gross += g; disc += d; tax += t;
            const stock = r.querySelector('.prod').selectedOptions[0]?.dataset.stock;
            const hint = r.querySelector('.stock');
            hint.textContent = stock !== undefined ? 'In stock: ' + stock : '';
            hint.className = 'small stock ' + (stock !== undefined && num(r.querySelector('.qty').value) > num(stock) ? 'text-danger' : 'text-muted');
        });
        const total = gross - disc + tax;
        $('tSub').textContent = fmt(gross); $('tDisc').textContent = fmt(disc); $('tTax').textContent = fmt(tax); $('tTotal').textContent = fmt(total);
        $('remaining').textContent = fmt(total - Math.round(num($('received').value) * 100));
    }
    const renumber = () => body.querySelectorAll('.item').forEach((r, i) => r.querySelectorAll('[name]').forEach(el => el.name = el.name.replace(/items\[\d+\]/, 'items[' + i + ']')));

    body.addEventListener('input', recalc);
    $('received').addEventListener('input', recalc);
    customer.addEventListener('change', recalc);
    body.addEventListener('change', e => {
        if (e.target.classList.contains('prod')) {
            const row = e.target.closest('tr'), price = e.target.selectedOptions[0]?.dataset.price;
            if (price && !row.querySelector('.price').value) row.querySelector('.price').value = price;
        }
        recalc();
    });
    body.addEventListener('click', e => { if (e.target.closest('.remove') && body.querySelectorAll('.item').length > 1) { e.target.closest('tr').remove(); renumber(); recalc(); } });
    $('add').addEventListener('click', () => {
        const c = body.querySelector('.item').cloneNode(true);
        c.querySelectorAll('input').forEach(i => i.value = ''); c.querySelectorAll('select').forEach(s => s.selectedIndex = 0);
        body.appendChild(c); renumber(); recalc();
    });
    recalc();
})();
</script>
@endpush
