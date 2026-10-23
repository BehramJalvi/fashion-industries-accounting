@extends('layouts.app')
@section('title', 'Roles & Permissions')
@section('content')
<div class="card">
    <table class="table align-middle mb-0">
        <thead class="table-light"><tr><th>Role</th><th>Users</th><th>Permissions</th><th></th></tr></thead>
        <tbody>
        @foreach ($roles as $role)
            <tr>
                <td class="fw-semibold">{{ $role->label }}</td>
                <td>{{ $role->users_count }}</td>
                <td>{{ $role->name === 'admin' ? 'Full access' : $role->permissions_count }}</td>
                <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="{{ route('roles.edit', $role) }}">{{ $role->name === 'admin' ? 'View' : 'Edit' }}</a></td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
@endsection
