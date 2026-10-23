@extends('layouts.app')
@section('title', 'Audit Log')
@section('content')
<div class="card">
    <div class="card-header">
        <form class="row g-2" method="GET">
            <div class="col-md-3"><select name="user_id" class="form-select form-select-sm"><option value="">All users</option>
                @foreach ($users as $u)<option value="{{ $u->id }}" @selected(request('user_id') == $u->id)>{{ $u->name }}</option>@endforeach</select></div>
            <div class="col-md-2"><select name="event" class="form-select form-select-sm"><option value="">All events</option>
                @foreach ($events as $e)<option @selected(request('event') === $e)>{{ $e }}</option>@endforeach</select></div>
            <div class="col-md-2"><input type="date" name="from" value="{{ request('from') }}" class="form-control form-control-sm"></div>
            <div class="col-md-2"><input type="date" name="to" value="{{ request('to') }}" class="form-control form-control-sm"></div>
            <div class="col-md-2"><button class="btn btn-sm btn-outline-secondary">Filter</button></div>
        </form>
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead class="table-light"><tr><th>When</th><th>User</th><th>Event</th><th>Record</th><th>Changes</th><th>IP</th></tr></thead>
            <tbody>
            @forelse ($logs as $log)
                <tr>
                    <td class="text-nowrap">{{ $log->created_at->format('d-M-Y H:i:s') }}</td>
                    <td>{{ $log->user?->name ?? '—' }}</td>
                    <td><span class="badge text-bg-secondary">{{ $log->event }}</span></td>
                    <td>{{ $log->auditable_type ? $log->auditable_type . ' #' . $log->auditable_id : '—' }}</td>
                    <td style="max-width: 420px;">
                        @if ($log->old_values || $log->new_values)
                            <details><summary class="small text-primary">view</summary>
                                <pre class="small mb-0">@if($log->old_values)before: {{ json_encode($log->old_values, JSON_UNESCAPED_UNICODE) }}
@endif @if($log->new_values)after:  {{ json_encode($log->new_values, JSON_UNESCAPED_UNICODE) }}@endif</pre>
                            </details>
                        @endif
                    </td>
                    <td class="small text-muted">{{ $log->ip_address }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-center text-muted py-4">No audit entries.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="card-footer">{{ $logs->links() }}</div>
</div>
@endsection
