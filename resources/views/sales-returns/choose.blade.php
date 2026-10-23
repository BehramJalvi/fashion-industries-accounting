@extends('layouts.app')
@section('title', 'New sales return')
@section('content')
<div class="card"><div class="card-body">
    <p class="text-muted">A return is made against a posted invoice, so the customer is credited what they paid and the goods go back at the cost they left at. Choose the invoice:</p>
    <form method="GET" action="{{ route('sales-returns.create') }}" class="row g-2" style="max-width:720px;">
        <div class="col-md-9"><select name="sale_id" class="form-select" required><option value="">Select invoice…</option>
            @foreach ($sales as $s)<option value="{{ $s->id }}">{{ $s->invoice_no }} · {{ $s->sale_date->format('d-M-Y') }} · {{ $s->customer?->name }} · {{ number_format((float) $s->total, 2) }}</option>@endforeach</select></div>
        <div class="col-md-3"><button class="btn btn-primary w-100">Continue</button></div>
    </form>
</div></div>
@endsection
