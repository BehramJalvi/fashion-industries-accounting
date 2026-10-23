@extends('layouts.app')
@section('title', 'New payment')
@section('content')
<div class="card"><div class="card-body">
    <p class="text-muted">Who are you paying? You will then see their open bills and can apply the money to them. Employees can be paid salary or reimbursed for money they spent.</p>
    <form method="GET" action="{{ route('payments.create') }}" class="row g-2" style="max-width:640px;">
        <div class="col-md-9"><select name="payee" class="form-select" required><option value="">Select supplier or employee…</option>
            @foreach ($payees as $group => $list)<optgroup label="{{ $group }}">@foreach ($list as $p)<option value="{{ $p['value'] }}">{{ $p['name'] }}</option>@endforeach</optgroup>@endforeach</select></div>
        <div class="col-md-3"><button class="btn btn-primary w-100">Continue</button></div>
    </form>
</div></div>
@endsection
