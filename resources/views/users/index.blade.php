@extends('layouts.app')
@section('title', 'Users')
@section('content')
<div class="card">
    <div class="card-header d-flex flex-wrap gap-2 justify-content-between align-items-center">
        <form class="d-flex gap-2" method="GET">
            <input class="form-control form-control-sm" name="q" value="{{ request('q') }}" placeholder="Search name or email">
            <select class="form-select form-select-sm" name="role" onchange="this.form.submit()">
                <option value="">All roles</option>
                @foreach ($roles as $role)
                    <option value="{{ $role->name }}" @selected(request('role') === $role->name)>{{ $role->label }}</option>
                @endforeach
            </select>
            <button class="btn btn-sm btn-outline-secondary">Search</button>
        </form>
        @can('users.manage')
            <a href="{{ route('users.create') }}" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg"></i> New user</a>
        @endcan
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light"><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Last login</th><th class="text-end">Actions</th></tr></thead>
            <tbody>
            @forelse ($users as $u)
                <tr>
                    <td>{{ $u->name }}</td>
                    <td>{{ $u->email }}</td>
                    <td>@foreach ($u->roles as $r)<span class="badge text-bg-info">{{ $r->label }}</span> @endforeach</td>
                    <td><span class="badge text-bg-{{ $u->is_active ? 'success' : 'secondary' }}">{{ $u->is_active ? 'Active' : 'Inactive' }}</span></td>
                    <td>{{ $u->last_login_at?->format('d-M-Y H:i') ?? '—' }}</td>
                    <td class="text-end">
                        @can('update', $u)<a href="{{ route('users.edit', $u) }}" class="btn btn-sm btn-outline-primary">Edit</a>@endcan
                        @can('toggle', $u)
                            <form method="POST" action="{{ route('users.toggle', $u) }}" class="d-inline"
                                  onsubmit="return confirm('{{ $u->is_active ? 'Deactivate' : 'Reactivate' }} {{ $u->name }}?')">
                                @csrf @method('PATCH')
                                <button class="btn btn-sm btn-outline-{{ $u->is_active ? 'danger' : 'success' }}">{{ $u->is_active ? 'Deactivate' : 'Reactivate' }}</button>
                            </form>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-center text-muted py-4">No users found.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="card-footer">{{ $users->links() }}</div>
</div>
@endsection
