@extends('layouts.app')
@section('title', $report['title'])
@section('content')
<form method="GET" class="card card-body mb-3 no-print"><div class="row g-2 align-items-end">
    <div class="col-md-2"><label class="form-label small">From</label><input type="date" name="from" value="{{ $from }}" class="form-control form-control-sm"></div>
    <div class="col-md-2"><label class="form-label small">To</label><input type="date" name="to" value="{{ $to }}" class="form-control form-control-sm"></div>
    <div class="col-md-2"><label class="form-label small">Status</label><select name="status" class="form-select form-select-sm">@foreach (['posted' => 'Posted', 'cancelled' => 'Cancelled', 'draft' => 'Drafts', 'all' => 'All'] as $v => $l)<option value="{{ $v }}" @selected($status === $v)>{{ $l }}</option>@endforeach</select></div>
    @if ($party)<div class="col-md-3"><label class="form-label small">{{ $party['label'] }}</label><select name="party_id" class="form-select form-select-sm"><option value="">All</option>@foreach ($party['list'] as $p)<option value="{{ $p->id }}" @selected($partyId == $p->id)>{{ $p->name }}</option>@endforeach</select></div>@endif
    <div class="col-md-1"><button class="btn btn-sm btn-outline-secondary w-100">Show</button></div>
</div>
<div class="mt-2 small">@foreach ($types as $k => $l)<a class="me-2 {{ $k === $type ? 'fw-bold' : '' }}" href="{{ route('reports.documents', $k) }}">{{ $l }}</a>@endforeach</div></form>
@include('reports._toolbar', ['title' => $report['title'], 'subtitle' => $from . ' to ' . $to . ($status !== 'posted' ? ' (' . $status . ')' : '')])
@if ($report['truncated'])<div class="alert alert-warning">Only the first {{ \App\Services\Reports\DocumentReportService::LIMIT }} rows are shown. Narrow the dates.</div>@endif
<div class="card"><div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
    <thead class="table-light"><tr>@foreach ($report['headers'] as [$label, $isMoney])<th class="{{ $isMoney ? 'text-end' : '' }}">{{ $label }}</th>@endforeach</tr></thead>
    <tbody>
    @forelse ($report['rows'] as $row)
        <tr class="{{ in_array('cancelled', $row, true) ? 'text-muted' : '' }}">@foreach ($row as $cell)@if (is_array($cell))<td class="text-end">{{ \App\Support\Money::display($cell['m']) }}</td>@else<td>{{ $cell }}</td>@endif @endforeach</tr>
    @empty<tr><td colspan="{{ count($report['headers']) }}" class="text-center text-muted py-4">Nothing in this period.</td></tr>@endforelse
    </tbody>
    @if ($report['rows'])<tfoot class="table-light fw-bold"><tr>@foreach ($report['headers'] as $i => [$label, $isMoney])<td class="{{ $isMoney ? 'text-end' : '' }}">{{ $isMoney ? \App\Support\Money::display($report['totals'][$i]) : ($i === 0 ? 'Total (' . count($report['rows']) . ')' : '') }}</td>@endforeach</tr></tfoot>@endif
</table></div></div>
@endsection
