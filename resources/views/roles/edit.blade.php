@extends('layouts.app')
@section('title', $role->label . ' permissions')
@section('content')
@php
    $locked = $role->name === 'admin';
@endphp
<form method="POST" action="{{ route('roles.update', $role) }}">
    @csrf @method('PUT')
    @if ($locked)
        <div class="alert alert-info">The Administrator role always has full access and cannot be changed.</div>
    @endif
    <div class="row g-3">
        @foreach ($groups as $group => $permissions)
            <div class="col-md-6 col-xl-4">
                <div class="card h-100">
                    <div class="card-header fw-semibold text-capitalize">{{ str_replace('_', ' ', $group) }}</div>
                    <div class="card-body">
                        @foreach ($permissions as $p)
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="permissions[]" value="{{ $p->id }}" id="p{{ $p->id }}"
                                       @checked($locked || in_array($p->id, $granted)) @disabled($locked)>
                                <label class="form-check-label" for="p{{ $p->id }}">{{ \Illuminate\Support\Str::after($p->name, '.') }}</label>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        @endforeach
    </div>
    <div class="mt-3">
        <a href="{{ route('roles.index') }}" class="btn btn-light">Back</a>
        @unless ($locked)<button class="btn btn-primary">Save permissions</button>@endunless
    </div>
</form>
@endsection
