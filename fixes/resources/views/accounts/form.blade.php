@extends('layouts.app')
@php
    $editing = $account->exists;
@endphp
@section('title', $editing ? 'Edit account' : 'New account')
@section('content')
<div class="card" style="max-width: 560px;"><div class="card-body">
    <form method="POST" action="{{ $editing ? route('accounts.update', $account) : route('accounts.store') }}">
        @csrf
        @if ($editing) @method('PUT') @endif

        <div class="row">
            <div class="col-4 mb-3">
                <label class="form-label">Code</label>
                <input name="code" class="form-control" value="{{ old('code', $account->code) }}" @readonly($editing) required>
            </div>
            <div class="col-8 mb-3">
                <label class="form-label">Name</label>
                <input name="name" class="form-control" value="{{ old('name', $account->name) }}" required maxlength="150">
            </div>
        </div>
        <div class="row">
            <div class="col-md-4 mb-3">
                <label class="form-label">Type</label>
                <select name="type" class="form-select" @disabled($editing)>
                    @foreach (['asset' => 'Asset', 'liability' => 'Liability', 'equity' => 'Equity', 'revenue' => 'Revenue', 'cogs' => 'Cost of goods sold', 'expense' => 'Expense'] as $v => $l)
                        <option value="{{ $v }}" @selected(old('type', $account->type) === $v)>{{ $l }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-4 mb-3">
                <label class="form-label">Normal balance</label>
                <select name="normal_balance" class="form-select" @disabled($editing)>
                    @foreach (['debit', 'credit'] as $v)
                        <option value="{{ $v }}" @selected(old('normal_balance', $account->normal_balance) === $v)>{{ ucfirst($v) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-4 mb-3">
                <label class="form-label">Sub-type</label>
                <input name="sub_type" class="form-control" value="{{ old('sub_type', $account->sub_type) }}" maxlength="50">
            </div>
        </div>
        @if ($editing)
            <div class="form-check mb-3">
                <input type="hidden" name="is_active" value="0">
                <input class="form-check-input" type="checkbox" name="is_active" value="1" id="active" @checked(old('is_active', $account->is_active)) @disabled($account->is_system)>
                <label class="form-check-label" for="active">Active @if ($account->is_system)<small class="text-muted">(system accounts stay active)</small>@endif</label>
            </div>
        @endif
        <p class="small text-muted">Code, type and normal balance cannot be changed after creation because posted history depends on them.</p>
        <a href="{{ route('accounts.index') }}" class="btn btn-light">Cancel</a>
        <button class="btn btn-primary">{{ $editing ? 'Save' : 'Create account' }}</button>
    </form>
</div></div>
@endsection
