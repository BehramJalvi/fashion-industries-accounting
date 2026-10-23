@extends('layouts.app')
@section('title', 'New purchase return')
@section('content')
<div class="card"><div class="card-body">
    <p class="text-muted">A return is made against a posted purchase, so the goods go back at the cost they were bought for. Choose the purchase:</p>
    <form method="GET" action="{{ route('purchase-returns.create') }}" class="row g-2" style="max-width:720px;">
        <div class="col-md-9"><select name="purchase_id" class="form-select" required><option value="">Select purchase…</option>
            @foreach ($purchases as $p)<option value="{{ $p->id }}">{{ $p->purchase_no }} · {{ $p->purchase_date->format('d-M-Y') }} · {{ $p->supplier?->name ?? 'Walk-in' }}{{ $p->supplier_ref ? ' · ' . $p->supplier_ref : '' }} · {{ number_format((float) $p->total, 2) }}</option>@endforeach</select></div>
        <div class="col-md-3"><button class="btn btn-primary w-100">Continue</button></div>
    </form>
</div></div>
@endsection
