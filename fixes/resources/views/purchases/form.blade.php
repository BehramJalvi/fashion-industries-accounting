@extends('layouts.app')
@php
    $editing = $purchase->exists;
@endphp
@section('title', $editing ? 'Edit ' . $purchase->purchase_no : 'New purchase')
@section('content')
@php
    $trim = fn ($n) => rtrim(rtrim((string) $n, '0'), '.');
    $rows = old('items', $editing
        ? $purchase->items->map(fn ($i) => ['product_id' => $i->product_id, 'qty' => $trim($i->qty), 'unit_cost' => $trim($i->unit_cost), 'discount' => (float) $i->discount ?: '', 'tax_rate' => (float) $i->tax_rate ?: ''])->all()
        : [[]]);
@endphp
<form method="POST" action="{{ $editing ? route('purchases.update', $purchase) : route('purchases.store') }}" id="purchaseForm">
    @csrf
    @if ($editing) @method('PUT') @endif

    <div class="card mb-3"><div class="card-body row g-3">
        <div class="col-md-4"><label class="form-label">Supplier</label>
            <select name="supplier_id" class="form-select"><option value="">Walk-in / cash purchase (no supplier)</option>
                @foreach ($suppliers as $s)<option value="{{ $s->id }}" @selected(old('supplier_id', $purchase->supplier_id) == $s->id)>{{ $s->name }}</option>@endforeach</select></div>
        <div class="col-md-2"><label class="form-label">Date</label>
            <input type="date" name="purchase_date" class="form-control" value="{{ old('purchase_date', $purchase->purchase_date?->toDateString() ?? now()->toDateString()) }}" required></div>
        <div class="col-md-3"><label class="form-label">Supplier invoice / reference</label>
            <input name="supplier_ref" class="form-control" value="{{ old('supplier_ref', $purchase->supplier_ref) }}" maxlength="60"></div>
        <div class="col-md-3"><label class="form-label">Due date <small class="text-muted">(optional)</small></label>
            <input type="date" name="due_date" class="form-control" value="{{ old('due_date', $purchase->due_date?->toDateString()) }}"></div>
        <div class="col-md-4"><label class="form-label">Paid by an employee from his own pocket?</label>
            <select name="funded_by_employee_id" class="form-select"><option value="">No, the business pays</option>
                @foreach ($employees as $e)<option value="{{ $e->id }}" @selected(old('funded_by_employee_id', $purchase->funded_by_employee_id) == $e->id)>{{ $e->name }}</option>@endforeach</select></div>
        <div class="col-md-8"><label class="form-label">Notes</label><input name="notes" class="form-control" value="{{ old('notes', $purchase->notes) }}" maxlength="1000"></div>
    </div></div>

    <div class="card mb-3">
        <table class="table align-middle mb-0" id="items">
            <thead class="table-light"><tr><th>Product</th><th style="width:110px">Qty</th><th style="width:120px">Unit cost</th><th style="width:110px">Discount</th><th style="width:90px">Tax %</th><th style="width:130px" class="text-end">Line total</th><th style="width:40px"></th></tr></thead>
            <tbody>
            @foreach ($rows as $i => $row)
                <tr class="item">
                    <td><select name="items[{{ $i }}][product_id]" class="form-select form-select-sm prod" required><option value="">Select product…</option>
                        @foreach ($products as $p)<option value="{{ $p->id }}" data-cost="{{ rtrim(rtrim((string) $p->purchase_price, '0'), '.') }}" @selected(($row['product_id'] ?? null) == $p->id)>{{ $p->code }} · {{ $p->name }} ({{ $p->unit?->short_name ?? $p->unit?->name }})</option>@endforeach</select></td>
                    <td><input name="items[{{ $i }}][qty]" class="form-control form-control-sm text-end qty" inputmode="decimal" value="{{ $row['qty'] ?? '' }}" required></td>
                    <td><input name="items[{{ $i }}][unit_cost]" class="form-control form-control-sm text-end cost" inputmode="decimal" value="{{ $row['unit_cost'] ?? '' }}" required></td>
                    <td><input name="items[{{ $i }}][discount]" class="form-control form-control-sm text-end disc" inputmode="decimal" value="{{ $row['discount'] ?? '' }}"></td>
                    <td><input name="items[{{ $i }}][tax_rate]" class="form-control form-control-sm text-end rate" inputmode="decimal" value="{{ $row['tax_rate'] ?? '' }}"></td>
                    <td class="text-end line">0.00</td>
                    <td><button type="button" class="btn btn-sm btn-outline-danger remove">&times;</button></td>
                </tr>
            @endforeach
            </tbody>
            <tfoot class="table-light">
                <tr><td colspan="7"><button type="button" class="btn btn-sm btn-outline-secondary" id="add"><i class="bi bi-plus"></i> Add line</button>
                    <span class="small text-muted ms-2">Stock is valued at cost after discount, before tax. Input tax is recorded separately.</span></td></tr>
                <tr><td colspan="5" class="text-end">Subtotal</td><td class="text-end" id="tSub">0.00</td><td></td></tr>
                <tr><td colspan="5" class="text-end">Discount</td><td class="text-end" id="tDisc">0.00</td><td></td></tr>
                <tr><td colspan="5" class="text-end">Tax</td><td class="text-end" id="tTax">0.00</td><td></td></tr>
                <tr class="fw-bold"><td colspan="5" class="text-end">Total</td><td class="text-end" id="tTotal">0.00</td><td></td></tr>
            </tfoot>
        </table>
    </div>

    <div class="card mb-3"><div class="card-body row g-3">
        <div class="col-md-4"><label class="form-label">Paid now (PKR)</label>
            <input name="paid_now" id="paidNow" class="form-control text-end" inputmode="decimal" value="{{ old('paid_now', (float) $purchase->paid_now ?: '') }}" placeholder="0 = on credit"></div>
        <div class="col-md-5"><label class="form-label">Paid from</label>
            <select name="paid_from_account_id" class="form-select"><option value="">—</option>
                @foreach ($accounts as $a)<option value="{{ $a->id }}" @selected(old('paid_from_account_id', $purchase->paid_from_account_id) == $a->id)>{{ $a->code }} · {{ $a->name }}</option>@endforeach</select></div>
        <div class="col-md-3"><label class="form-label">Remaining payable</label><div class="form-control-plaintext fw-semibold text-end" id="remaining">0.00</div></div>
        <div class="col-12 small text-muted">The unpaid amount is owed to the supplier, or to the employee if he paid. Pay it later from Payments.</div>
    </div></div>

    <a href="{{ route('purchases.index') }}" class="btn btn-light">Cancel</a>
    <button name="action" value="draft" class="btn btn-outline-primary">Save as draft</button>
    @can('purchases.post')<button name="action" value="post" class="btn btn-primary">Save &amp; post</button>@endcan
</form>
@endsection

@push('scripts')
<script>
(function () {
    const body = document.querySelector('#items tbody');
    const $ = id => document.getElementById(id);
    const tSub = $('tSub'), tDisc = $('tDisc'), tTax = $('tTax'), tTotal = $('tTotal'), remaining = $('remaining'), paidNow = $('paidNow');
    const num = v => parseFloat(v) || 0;
    const fmt = c => (c / 100).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    function recalc() {
        let gross = 0, disc = 0, tax = 0;
        body.querySelectorAll('.item').forEach(r => {
            const g = Math.round(num(r.querySelector('.qty').value) * num(r.querySelector('.cost').value) * 100);
            const d = Math.round(num(r.querySelector('.disc').value) * 100);
            const net = g - d, t = Math.round(net * num(r.querySelector('.rate').value) / 100);
            r.querySelector('.line').textContent = fmt(net + t);
            gross += g; disc += d; tax += t;
        });
        const total = gross - disc + tax;
        tSub.textContent = fmt(gross); tDisc.textContent = fmt(disc); tTax.textContent = fmt(tax); tTotal.textContent = fmt(total);
        remaining.textContent = fmt(total - Math.round(num(paidNow.value) * 100));
    }
    const renumber = () => body.querySelectorAll('.item').forEach((r, i) => r.querySelectorAll('[name]').forEach(el => el.name = el.name.replace(/items\[\d+\]/, 'items[' + i + ']')));

    body.addEventListener('input', recalc);
    paidNow.addEventListener('input', recalc);
    body.addEventListener('change', e => {
        if (e.target.classList.contains('prod')) {
            const row = e.target.closest('tr'), cost = e.target.selectedOptions[0]?.dataset.cost;
            if (cost && !row.querySelector('.cost').value) row.querySelector('.cost').value = cost;
            recalc();
        }
    });
    body.addEventListener('click', e => { if (e.target.closest('.remove') && body.querySelectorAll('.item').length > 1) { e.target.closest('tr').remove(); renumber(); recalc(); } });
    document.getElementById('add').addEventListener('click', () => {
        const c = body.querySelector('.item').cloneNode(true);
        c.querySelectorAll('input').forEach(i => i.value = ''); c.querySelectorAll('select').forEach(s => s.selectedIndex = 0);
        body.appendChild(c); renumber(); recalc();
    });
    recalc();
})();
</script>
@endpush
