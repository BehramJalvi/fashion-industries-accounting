@extends('layouts.app')
@php
    $editing = $user->exists;
@endphp
@section('title', $editing ? 'Edit user' : 'New user')
@section('content')
<div class="card" style="max-width: 640px;">
    <div class="card-body">
        <form method="POST" action="{{ $editing ? route('users.update', $user) : route('users.store') }}">
            @csrf
            @if ($editing) @method('PUT') @endif

            <div class="mb-3">
                <label class="form-label">Name</label>
                <input name="name" class="form-control" value="{{ old('name', $user->name) }}" required maxlength="100">
            </div>
            <div class="mb-3">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-control" value="{{ old('email', $user->email) }}" required maxlength="150">
            </div>
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Password @if ($editing)<small class="text-muted">(leave blank to keep)</small>@endif</label>
                    <input type="password" name="password" class="form-control" autocomplete="new-password" @required(! $editing)>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Confirm password</label>
                    <input type="password" name="password_confirmation" class="form-control" autocomplete="new-password">
                </div>
            </div>
            <div class="form-text mb-3">At least 10 characters, with letters and numbers.</div>

            <div class="mb-3">
                <label class="form-label d-block">Role</label>
                @php
                    $current = old('roles', $user->roles->pluck('id')->all());
                @endphp
                @foreach ($roles as $role)
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="checkbox" name="roles[]" value="{{ $role->id }}" id="role{{ $role->id }}"
                               @checked(in_array($role->id, $current))>
                        <label class="form-check-label" for="role{{ $role->id }}">{{ $role->label }}</label>
                    </div>
                @endforeach
            </div>

            @unless ($editing)
                <div class="form-check mb-3">
                    <input type="hidden" name="is_active" value="0">
                    <input class="form-check-input" type="checkbox" name="is_active" value="1" id="active" @checked(old('is_active', true))>
                    <label class="form-check-label" for="active">Active</label>
                </div>
            @endunless

            <a href="{{ route('users.index') }}" class="btn btn-light">Cancel</a>
            <button class="btn btn-primary">{{ $editing ? 'Save changes' : 'Create user' }}</button>
        </form>
    </div>
</div>
@endsection
