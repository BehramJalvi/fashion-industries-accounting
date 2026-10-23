@extends('layouts.app')
@section('title', 'New production')
@section('content')
@php
    $rows = old('inputs', [[]]);
    $outputRows = old('outputs', [[
        'product_id' => old('output_product_id'),
        'qty' => old('output_qty'),
        'allocation_basis' => '',
    ]]);
@endphp
<form method="POST" action="{{ route('production-orders.store') }}">
    @csrf
    <div class="alert alert-info">
        Enter the raw materials consumed and every finished product produced. Costs are allocated using each output's estimated production cost basis, so products with different units or costs are not allocated by quantity alone.
    </div>
    <div class="card mb-3"><div class="card-body row g-3">
        <div class="col-md-3"><label class="form-label">Date</label><input type="date" name="order_date" class="form-control" value="{{ old('order_date', now()->toDateString()) }}" required></div>
        <div class="col-md-9"><label class="form-label">Notes</label><input name="notes" class="form-control" value="{{ old('notes') }}" maxlength="1000" placeholder="Optional batch or job reference"></div>
    </div></div>

    <div class="card mb-3">
        <div class="card-header fw-semibold">Raw material consumed</div>
        <div class="table-responsive"><table class="table align-middle mb-0" id="inputs">
            <thead class="table-light"><tr><th>Raw material</th><th style="width:180px">Quantity consumed</th><th style="width:40px"></th></tr></thead>
            <tbody>
            @foreach ($rows as $i => $row)
                <tr class="input-row">
                    <td><select name="inputs[{{ $i }}][product_id]" class="form-select" required><option value="">Select raw material…</option>
                        @foreach ($products as $product)<option value="{{ $product->id }}" @selected(($row['product_id'] ?? null) == $product->id)>{{ $product->code }} · {{ $product->name }}</option>@endforeach</select></td>
                    <td><input name="inputs[{{ $i }}][qty]" class="form-control text-end" inputmode="decimal" value="{{ $row['qty'] ?? '' }}" required></td>
                    <td><button type="button" class="btn btn-sm btn-outline-danger remove">&times;</button></td>
                </tr>
            @endforeach
            </tbody>
            <tfoot class="table-light"><tr><td colspan="3"><button type="button" class="btn btn-sm btn-outline-secondary" id="add-input"><i class="bi bi-plus"></i> Add raw material</button>
                <span class="small text-muted ms-2">Consumed materials are valued at moving-average cost.</span></td></tr></tfoot>
        </table></div>
    </div>

    <div class="card mb-3">
        <div class="card-header fw-semibold">Finished products produced</div>
        <div class="table-responsive"><table class="table align-middle mb-0" id="outputs">
            <thead class="table-light"><tr><th>Finished product</th><th style="width:170px">Quantity produced</th><th style="width:230px">Estimated production cost (PKR)</th><th style="width:40px"></th></tr></thead>
            <tbody>
            @foreach ($outputRows as $i => $row)
                <tr class="output-row">
                    <td><select name="outputs[{{ $i }}][product_id]" class="form-select" required><option value="">Select finished product…</option>
                        @foreach ($products as $product)<option value="{{ $product->id }}" @selected(($row['product_id'] ?? null) == $product->id)>{{ $product->code }} · {{ $product->name }}</option>@endforeach</select></td>
                    <td><input name="outputs[{{ $i }}][qty]" class="form-control text-end" inputmode="decimal" value="{{ $row['qty'] ?? '' }}" required></td>
                    <td><input name="outputs[{{ $i }}][allocation_basis]" class="form-control text-end" inputmode="decimal" value="{{ $row['allocation_basis'] ?? '' }}" placeholder="Required for multiple outputs"></td>
                    <td><button type="button" class="btn btn-sm btn-outline-danger remove">&times;</button></td>
                </tr>
            @endforeach
            </tbody>
            <tfoot class="table-light"><tr><td colspan="4"><button type="button" class="btn btn-sm btn-outline-secondary" id="add-output"><i class="bi bi-plus"></i> Add finished product</button>
                <span class="small text-muted ms-2">Use each output's estimated total cost as its allocation basis; the complete production cost is allocated exactly across all outputs.</span></td></tr></tfoot>
        </table></div>
    </div>

    <div class="card mb-3"><div class="card-header fw-semibold">Processing fee</div><div class="card-body row g-3">
        <div class="col-md-3"><label class="form-label">Fee (PKR)</label><input name="processing_fee" class="form-control text-end" inputmode="decimal" value="{{ old('processing_fee', '0.00') }}" required></div>
        <div class="col-md-4"><label class="form-label">Processor / supplier</label><select name="processor_supplier_id" class="form-select"><option value="">—</option>
            @foreach ($suppliers as $supplier)<option value="{{ $supplier->id }}" @selected(old('processor_supplier_id') == $supplier->id)>{{ $supplier->name }}</option>@endforeach</select>
            <div class="form-text">Required when the fee is owed; optional if already paid.</div></div>
        <div class="col-md-5"><label class="form-label">Paid now from (cash / bank)</label><select name="paid_from_account_id" class="form-select"><option value="">— Pay processor later —</option>
            @foreach ($cashAccounts as $account)<option value="{{ $account->id }}" @selected(old('paid_from_account_id') == $account->id)>{{ $account->code }} · {{ $account->name }}</option>@endforeach</select></div>
        <div class="col-12 small text-muted">Leave “Paid now from” blank and select the processor to record the fee as payable. The fee is included in finished inventory cost.</div>
    </div></div>

    <div class="mt-3">
        <a href="{{ route('production-orders.index') }}" class="btn btn-light">Cancel</a>
        <button name="action" value="draft" class="btn btn-outline-primary">Save as draft</button>
        @can('production_orders.post')<button name="action" value="post" class="btn btn-primary">Save &amp; post</button>@endcan
    </div>
</form>
@endsection
@push('scripts')
<script>
(function () {
    function configureRows(tableId, rowClass, addButtonId, inputName) {
        const body = document.querySelector('#' + tableId + ' tbody');
        const renumber = () => body.querySelectorAll('.' + rowClass).forEach((row, i) => row.querySelectorAll('[name]').forEach(el => el.name = el.name.replace(new RegExp(inputName + '\\[\\d+\\]'), inputName + '[' + i + ']')));
        body.addEventListener('click', event => {
            if (event.target.closest('.remove') && body.querySelectorAll('.' + rowClass).length > 1) {
                event.target.closest('tr').remove();
                renumber();
            }
        });
        document.getElementById(addButtonId).addEventListener('click', () => {
            const row = body.querySelector('.' + rowClass).cloneNode(true);
            row.querySelectorAll('input').forEach(input => input.value = '');
            row.querySelectorAll('select').forEach(select => select.selectedIndex = 0);
            body.appendChild(row);
            renumber();
        });
    }
    configureRows('inputs', 'input-row', 'add-input', 'inputs');
    configureRows('outputs', 'output-row', 'add-output', 'outputs');
})();
</script>
@endpush
