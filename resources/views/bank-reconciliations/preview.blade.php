@extends('layouts.app')
@section('title', 'Preview bank statement')
@section('content')
<div class="card mb-3"><div class="card-body">
    <h5>{{ $account->code }} · {{ $account->name }}</h5>
    <div>Statement period: {{ \Carbon\Carbon::parse($header['period_start'])->format('d-M-Y') }} – {{ \Carbon\Carbon::parse($header['period_end'])->format('d-M-Y') }}</div>
    <div class="row g-3 mt-1">
        <div class="col-sm-6 col-lg-3"><div class="small text-muted">Opening balance</div><strong>{{ number_format((float) $header['opening_balance'], 2) }}</strong></div>
        <div class="col-sm-6 col-lg-3"><div class="small text-muted">Imported net movement</div><strong>{{ \App\Support\Money::display($statementTotalCents) }}</strong></div>
        <div class="col-sm-6 col-lg-3"><div class="small text-muted">Expected closing balance</div><strong>{{ \App\Support\Money::display($expectedCloseCents) }}</strong></div>
        <div class="col-sm-6 col-lg-3"><div class="small text-muted">Difference from stated close</div><strong class="{{ $differenceCents === 0 ? 'text-success' : 'text-danger' }}">{{ \App\Support\Money::display($differenceCents) }}</strong></div>
    </div>
    @if ($differenceCents !== 0)<div class="alert alert-warning mt-3 mb-0">The statement totals do not currently balance. You can import the preview and correct the draft lines before reconciling.</div>@endif
</div></div>
<div class="card">
    <div class="card-header">Preview · {{ count($lines) }} statement line(s)</div>
    <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
        <thead class="table-light"><tr><th>Date</th><th>Description</th><th>Reference</th><th class="text-end">Signed amount</th></tr></thead>
        <tbody>@foreach ($lines as $line)
            <tr><td>{{ \Carbon\Carbon::parse($line['statement_date'])->format('d-M-Y') }}</td><td>{{ $line['description'] }}</td><td>{{ $line['reference'] }}</td><td class="text-end">{{ number_format((float) $line['signed_amount'], 2) }}</td></tr>
        @endforeach</tbody>
    </table></div>
    <div class="card-footer d-flex gap-2">
        <a href="{{ route('bank-reconciliations.create') }}" class="btn btn-light">Discard preview</a>
        <form method="POST" action="{{ route('bank-reconciliations.import') }}">@csrf
            <input type="hidden" name="preview_token" value="{{ $previewToken }}">
            <button class="btn btn-primary">Confirm and import statement</button>
        </form>
    </div>
</div>
@endsection
