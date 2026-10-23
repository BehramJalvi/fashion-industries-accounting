@extends('layouts.app')
@section('title', 'Reports')
@section('content')
@php
    $groups = [
        'Financial statements' => [['reports.trial-balance', 'Trial Balance', 'Debits and credits of every account, with opening, movement and closing'], ['reports.profit-loss', 'Profit & Loss', 'Sales, cost of goods sold, expenses and net profit for a period'], ['reports.balance-sheet', 'Balance Sheet', 'Assets, liabilities and equity as at a date'], ['reports.cash-flow', 'Cash Flow', 'Money in and out of cash and bank, by kind of transaction']],
        'Receivables and payables' => [['reports.receivables', 'Outstanding Receivables', 'Who owes you, aged by days overdue'], ['reports.payables', 'Outstanding Payables', 'What you owe suppliers and employees, aged'], ['reports.balances', 'Customer Balances', 'Every customer balance as at a date', ['type' => 'customers']], ['reports.balances', 'Supplier Balances', 'Every supplier balance as at a date', ['type' => 'suppliers']]],
        'Stock' => [['reports.stock-valuation', 'Stock Valuation', 'Quantity, average cost and value per product, checked against the Inventory account'], ['inventory.ledger', 'Inventory Ledger', 'Opening, movements and closing for one product']],
        'Ledgers and daily' => [['ledgers.index', 'General Ledger', 'Any account, filtered by date, customer / supplier and transaction type'], ['ledgers.index', 'Cash Book', 'All cash accounts', ['preset' => 'cash']], ['ledgers.index', 'Bank Book', 'All bank accounts', ['preset' => 'bank']], ['ledgers.index', 'Expense Ledger', 'All expense and cost accounts', ['preset' => 'expense']], ['ledgers.index', 'Owner Capital Ledger', 'Capital and drawings', ['preset' => 'owner']], ['reports.day-book', 'Day Book', 'Every journal entry of a day or period']],
    ];
@endphp
<div class="row g-3">
@foreach ($groups as $heading => $items)
    <div class="col-lg-6"><div class="card h-100"><div class="card-header fw-semibold">{{ $heading }}</div><div class="list-group list-group-flush">
        @foreach ($items as $item)
            @if (Route::has($item[0]))<a class="list-group-item list-group-item-action" href="{{ route($item[0], $item[3] ?? []) }}"><div class="fw-semibold">{{ $item[1] }}</div><div class="small text-muted">{{ $item[2] }}</div></a>@endif
        @endforeach
        @if ($heading === 'Ledgers and daily')
            <a class="list-group-item list-group-item-action" href="{{ route('customers.index') }}"><div class="fw-semibold">Customer and Supplier Ledgers</div><div class="small text-muted">Open a customer or supplier and press Ledger for a printable statement</div></a>
        @endif
    </div></div></div>
@endforeach
    <div class="col-12"><div class="card"><div class="card-header fw-semibold">Transaction listings</div><div class="card-body d-flex flex-wrap gap-2">
        @foreach ($documentTypes as $key => $label)<a class="btn btn-outline-secondary" href="{{ route('reports.documents', $key) }}">{{ $label }}</a>@endforeach
    </div></div></div>
</div>
@endsection
