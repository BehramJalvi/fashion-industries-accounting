@extends('layouts.app')
@section('title', 'Return against ' . $purchase->purchase_no)
@section('content')
@php
    $fmt = fn (int $milli) => rtrim(rtrim(number_format($milli / 1000, 3), '0'), '.') ?: '0';
@endphp
@php
    $noParty = ! $purchase->supplier_id && ! $purchase->funded_by_employee_id;
@endphp
<form method="POST" action="{{ route('purchase-returns.store') }}">
    @csrf
    <input type="hidden" name="purchase_id" value="{{ $purchase->id }}">

    <div class="card mb-3"><div class="card-body">
        <div class="fs-5">{{ $purchase->purchase_no }} · {{ $purchase->supplier?->name ?? 'Walk-in / cash purchase' }}</div>
        <div class="text-muted">Bought {{ $purchase->purchase_date->format('d-M-Y') }} · total {{ number_format((float) $purchase->total, 2) }}</div>
    </div></div>

    <div class="card mb-3">
        <table class="table align-middle mb-0">
            <thead class="table-light"><tr><th>Product</th><th class="text-end">Bought</th><th class="text-end">Already returned</th><th class="text-end">Can still return</th><th style="width:150px">Return qty</th></tr></thead>
            <tbody>
            @foreach ($purchase->items->unique('product_id') as $item)
                @php
                    $a = $available[$item->product_id];
                @endphp
                <tr><td>{{ $item->product->code }} · {{ $item->product->name }}</td>
                    <td class="text-end">{{ $fmt($a['purchased']) }}</td><td class="text-end">{{ $fmt($a['returned']) }}</td><td class="text-end">{{ $fmt(max($a['available'], 0)) }}</td>
                    <td><input name="qty[{{ $item->product_id }}]" class="form-control form-control-sm text-end" inputmode="decimal" value="{{ old('qty.' . $item->product_id) }}" @disabled($a['available'] <= 0)></td></tr>
            @endforeach
            </tbody>
        </table>
    </div>

    <div class="card mb-3"><div class="card-body row g-3">
        <div class="col-md-3"><label class="form-label">Return date</label><input type="date" name="return_date" class="form-control" value="{{ old('return_date', now()->toDateString()) }}" required></div>
        <div class="col-md-5"><label class="form-label">Reason</label><input name="reason" class="form-control" value="{{ old('reason') }}" maxlength="150"></div>
        <div class="col-md-4"><label class="form-label">Notes</label><input name="notes" class="form-control" value="{{ old('notes') }}"></div>
        <div class="col-md-4"><label class="form-label">Cash refunded now (PKR)</label><input name="refund_now" class="form-control text-end" inputmode="decimal" value="{{ old('refund_now') }}" placeholder="0 = credit note"></div>
        <div class="col-md-5"><label class="form-label">Refund received into</label>
            <select name="refund_account_id" class="form-select"><option value="">—</option>@foreach ($accounts as $a)<option value="{{ $a->id }}" @selected(old('refund_account_id') == $a->id)>{{ $a->code }} · {{ $a->name }}</option>@endforeach</select></div>
        <div class="col-12 small text-muted">
            @if ($noParty) This purchase has no supplier account, so the whole value must be refunded in cash or bank.
            @else Whatever is not refunded now is deducted from what we owe {{ $purchase->fundedByEmployee?->name ?? $purchase->supplier?->name }} (credit note). @endif
            Goods go back at their original cost; the value is worked out for you.</div>
    </div></div>

    <a href="{{ route('purchases.show', $purchase) }}" class="btn btn-light">Cancel</a>
    <button name="action" value="draft" class="btn btn-outline-primary">Save as draft</button>
    @can('purchase_returns.post')<button name="action" value="post" class="btn btn-primary">Save &amp; post</button>@endcan
</form>
@endsection
