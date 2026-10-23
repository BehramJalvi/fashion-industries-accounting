@extends('layouts.app')
@section('title', 'New stock adjustment')
@section('content')
@php
    $rows = old('items', [[]]);
@endphp
<form method="POST" action="{{ route('stock-adjustments.store') }}">
    @csrf
    <div class="card mb-3"><div class="card-body row g-3">
        <div class="col-md-3"><label class="form-label">Date</label><input type="date" name="adjustment_date" class="form-control" value="{{ old('adjustment_date', now()->toDateString()) }}" required></div>
        <div class="col-md-5"><label class="form-label">Reason</label><input name="reason" class="form-control" value="{{ old('reason') }}" required maxlength="150" placeholder="e.g. Physical count difference, damaged goods"></div>
        <div class="col-md-4"><label class="form-label">Notes</label><input name="notes" class="form-control" value="{{ old('notes') }}"></div>
    </div></div>

    <div class="card">
        <table class="table align-middle mb-0" id="items">
            <thead class="table-light"><tr><th>Product</th><th style="width:130px">Direction</th><th style="width:140px">Quantity</th><th style="width:160px">Unit cost <small class="text-muted">(stock in only)</small></th><th style="width:40px"></th></tr></thead>
            <tbody>
            @foreach ($rows as $i => $row)
                <tr class="item">
                    <td><select name="items[{{ $i }}][product_id]" class="form-select form-select-sm" required><option value="">Select product…</option>
                        @foreach ($products as $p)<option value="{{ $p->id }}" @selected(($row['product_id'] ?? null) == $p->id)>{{ $p->code }} · {{ $p->name }}</option>@endforeach</select></td>
                    <td><select name="items[{{ $i }}][direction]" class="form-select form-select-sm dir">
                        <option value="out" @selected(($row['direction'] ?? 'out') === 'out')>Out (loss)</option><option value="in" @selected(($row['direction'] ?? '') === 'in')>In (gain)</option></select></td>
                    <td><input name="items[{{ $i }}][qty]" class="form-control form-control-sm text-end" inputmode="decimal" value="{{ $row['qty'] ?? '' }}" required></td>
                    <td><input name="items[{{ $i }}][unit_cost]" class="form-control form-control-sm text-end cost" inputmode="decimal" value="{{ $row['unit_cost'] ?? '' }}"></td>
                    <td><button type="button" class="btn btn-sm btn-outline-danger remove">&times;</button></td>
                </tr>
            @endforeach
            </tbody>
            <tfoot class="table-light"><tr><td colspan="5"><button type="button" class="btn btn-sm btn-outline-secondary" id="add"><i class="bi bi-plus"></i> Add item</button>
                <span class="small text-muted ms-2">Stock going out is valued at the current average cost automatically.</span></td></tr></tfoot>
        </table>
    </div>
    <div class="mt-3">
        <a href="{{ route('stock-adjustments.index') }}" class="btn btn-light">Cancel</a>
        <button name="action" value="draft" class="btn btn-outline-primary">Save as draft</button>
        @can('stock_adjustments.post')<button name="action" value="post" class="btn btn-primary">Save &amp; post</button>@endcan
    </div>
</form>
@endsection
@push('scripts')
<script>
(function () {
    const body = document.querySelector('#items tbody');
    const sync = r => { const out = r.querySelector('.dir').value === 'out'; const c = r.querySelector('.cost'); c.disabled = out; if (out) c.value = ''; c.required = !out; };
    const renumber = () => body.querySelectorAll('.item').forEach((r, i) => r.querySelectorAll('[name]').forEach(el => el.name = el.name.replace(/items\[\d+\]/, 'items[' + i + ']')));
    body.addEventListener('change', e => { if (e.target.classList.contains('dir')) sync(e.target.closest('tr')); });
    body.addEventListener('click', e => { if (e.target.closest('.remove') && body.querySelectorAll('.item').length > 1) { e.target.closest('tr').remove(); renumber(); } });
    document.getElementById('add').addEventListener('click', () => {
        const c = body.querySelector('.item').cloneNode(true);
        c.querySelectorAll('input').forEach(i => i.value = ''); c.querySelectorAll('select').forEach(s => s.selectedIndex = 0);
        body.appendChild(c); renumber(); sync(c);
    });
    body.querySelectorAll('.item').forEach(sync);
})();
</script>
@endpush
