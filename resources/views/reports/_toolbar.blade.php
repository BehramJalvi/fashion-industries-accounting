{{-- Print + CSV buttons. Expects $title and $subtitle for the print header. --}}
<div class="d-none d-print-block mb-3">
    <div class="fs-4 fw-bold">{{ \App\Models\Setting::get('company_name', 'Fashion Industries INC') }}</div>
    <div class="fs-5">{{ $title }}</div>
    <div class="text-muted">{{ $subtitle }}</div>
</div>
<div class="no-print d-flex gap-2 mb-3">
    <button type="button" class="btn btn-sm btn-outline-primary" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
    @if ($showExport ?? true)
        @can('reports.export')<a class="btn btn-sm btn-outline-success" href="{{ request()->fullUrlWithQuery(['export' => 'csv']) }}"><i class="bi bi-filetype-csv"></i> Export CSV</a>@endcan
    @endif
    <a class="btn btn-sm btn-light ms-auto" href="{{ route('reports.index') }}">All reports</a>
</div>
