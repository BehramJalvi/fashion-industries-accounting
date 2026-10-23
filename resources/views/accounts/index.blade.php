@extends('layouts.app')
@section('title', 'Chart of Accounts')
@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <span class="text-muted">Balances are calculated live from the journal.</span>
    @can('accounts.create')<a href="{{ route('accounts.create') }}" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg"></i> New account</a>@endcan
</div>

@foreach (['asset' => 'Assets', 'liability' => 'Liabilities', 'equity' => 'Equity', 'revenue' => 'Revenue', 'cogs' => 'Cost of Goods Sold', 'expense' => 'Operating Expenses'] as $type => $label)
    @continue(! isset($groups[$type]))
    <div class="card mb-3">
        <div class="card-header fw-semibold">{{ $label }}</div>
        <table class="table table-sm table-hover align-middle mb-0">
            <thead class="table-light"><tr><th style="width:90px">Code</th><th>Account</th><th>Normal</th><th>Sub-ledger</th><th class="text-end">Balance (PKR)</th><th></th></tr></thead>
            <tbody>
            @foreach ($groups[$type] as $a)
                <tr class="{{ $a->is_active ? '' : 'text-muted' }}">
                    <td>{{ $a->code }}</td>
                    <td>{{ $a->name }}
                        @if ($a->is_system)<span class="badge text-bg-light border">system</span>@endif
                        @unless ($a->is_active)<span class="badge text-bg-secondary">inactive</span>@endunless</td>
                    <td class="text-capitalize">{{ $a->normal_balance }}</td>
                    <td class="text-capitalize">{{ $a->party_type ?? '—' }}</td>
                    <td class="text-end">{{ number_format($a->balance, 2) }}</td>
                    <td class="text-end">
                        @can('accounts.update')<a href="{{ route('accounts.edit', $a) }}" class="btn btn-sm btn-outline-primary">Edit</a>@endcan
                        @can('accounts.delete')
                            @unless ($a->is_system)
                                <form method="POST" action="{{ route('accounts.destroy', $a) }}" class="d-inline" onsubmit="return confirm('Delete account {{ $a->code }}?')">
                                    @csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger">Delete</button>
                                </form>
                            @endunless
                        @endcan
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endforeach
@endsection
