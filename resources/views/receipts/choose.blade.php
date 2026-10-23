@extends('layouts.app')
@section('title', 'New receipt')
@section('content')
<div class="card"><div class="card-body">
    <p class="text-muted">Who paid? You will then see their open invoices and can apply the money to them.</p>
    <form method="GET" action="{{ route('receipts.create') }}" class="row g-2" style="max-width:640px;">
        <div class="col-md-9"><select name="customer_id" class="form-select" required><option value="">Select customer…</option>
            @foreach ($customers as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></div>
        <div class="col-md-3"><button class="btn btn-primary w-100">Continue</button></div>
    </form>
</div></div>
@endsection
