@extends('layouts.app')
@section('title', $cfg['labels']['plural'])
@section('content')
@php
    $L = $cfg['labels']; $p = $cfg['prefix'];
    $money = fn (int $c) => number_format(abs($c) / 100, 2);
@endphp
<div class="row g-3 mb-3">
    <div class="col-md-4"><div class="card"><div class="card-body">
        <div class="text-muted small">Total {{ strtolower($L['balance']) }}</div>
        <div class="fs-4 {{ $total < 0 ? 'text-danger' : '' }}">PKR {{ $money($total) }} <small class="fs-6 text-muted">{{ $total < 0 ? $L['negative'] : '' }}</small></div>
    </div></div></div>
</div>

<div class="card">
    <div class="card-header d-flex flex-wrap gap-2 justify-content-between">
        <form class="d-flex gap-2" method="GET">
            <input name="q" value="{{ request('q') }}" class="form-control form-control-sm" placeholder="Search name{{ $cfg['hasCode'] ? ' or code' : '' }}">
            <select name="status" class="form-select form-select-sm" onchange="this.form.submit()"><option value="">Any status</option>
                <option value="active" @selected(request('status') === 'active')>Active</option><option value="inactive" @selected(request('status') === 'inactive')>Inactive</option></select>
            <button class="btn btn-sm btn-outline-secondary">Search</button>
        </form>
        @can($p . '.create')<a href="{{ route($p . '.create') }}" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg"></i> New {{ strtolower($L['singular']) }}</a>@endcan
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light"><tr>@foreach ($cfg['columns'] as $header => $attr)<th>{{ $header }}</th>@endforeach
                <th class="text-end">{{ $L['balance'] }} (PKR)</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @forelse ($items as $item)
                @php
                    $bal = $balances[$item->id] ?? 0;
                @endphp
                <tr class="{{ $item->is_active ? '' : 'text-muted' }}">
                    @foreach ($cfg['columns'] as $header => $attr)<td>{{ $item->{$attr} }}</td>@endforeach
                    <td class="text-end {{ $bal < 0 ? 'text-danger' : '' }}">{{ $money($bal) }}
                        @if ($bal < 0)<small class="d-block">{{ $L['negative'] }}</small>@endif</td>
                    <td><span class="badge text-bg-{{ $item->is_active ? 'success' : 'secondary' }}">{{ $item->is_active ? 'Active' : 'Inactive' }}</span></td>
                    <td class="text-end text-nowrap">
                        <a href="{{ route($p . '.ledger', $item->id) }}" class="btn btn-sm btn-outline-secondary">Ledger</a>
                        @can($p . '.update')<a href="{{ route($p . '.edit', $item->id) }}" class="btn btn-sm btn-outline-primary">Edit</a>@endcan
                        @can($p . '.delete')
                            <form method="POST" action="{{ route($p . '.destroy', $item->id) }}" class="d-inline" onsubmit="return confirm('Delete {{ $item->name }}?')">
                                @csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger">Delete</button></form>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="{{ count($cfg['columns']) + 3 }}" class="text-center text-muted py-4">Nothing found.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="card-footer">{{ $items->links() }}</div>
</div>
@endsection
