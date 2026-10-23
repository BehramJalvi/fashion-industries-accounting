<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Purchase {{ $purchase->purchase_no }}</title>
    <style>
        @page { size: A4; margin: 14mm; }
        * { box-sizing: border-box; }
        body { font: 12px Arial, Helvetica, sans-serif; color: #111; margin: 0; background: #e9ecef; }
        .toolbar { display: flex; align-items: center; gap: 8px; padding: 10px 16px; color: #fff; background: #1f2a44; }
        .toolbar a, .toolbar button { border: 0; border-radius: 4px; padding: 7px 12px; color: #1f2a44; background: #fff; text-decoration: none; cursor: pointer; }
        .sheet { position: relative; width: 190mm; min-height: 260mm; margin: 14px auto; padding: 12mm; background: #fff; }
        .company { text-align: center; border-bottom: 2px solid #1f2a44; padding-bottom: 10px; }
        .company h1 { margin: 0 0 4px; font-size: 24px; }
        .company p { margin: 2px 0; }
        .heading { display: flex; justify-content: space-between; align-items: flex-start; margin: 18px 0; }
        .heading h2 { margin: 0 0 8px; font-size: 18px; text-transform: uppercase; }
        .muted { color: #555; }
        .supplier { margin-bottom: 18px; }
        .supplier h3 { margin: 0 0 5px; font-size: 14px; }
        .supplier p { margin: 2px 0; }
        table { width: 100%; border-collapse: collapse; }
        .items th, .items td { border: 1px solid #777; padding: 6px; }
        .items th { background: #f1f3f5; text-align: left; }
        .right { text-align: right; }
        .totals { width: 45%; margin: 12px 0 0 auto; }
        .totals td { padding: 4px 7px; }
        .totals tr:last-child { border-top: 2px solid #111; font-size: 14px; font-weight: 700; }
        .payment { margin-top: 18px; padding: 10px; border: 1px solid #bbb; }
        .cancelled { position: absolute; inset: 40% 5% auto; transform: rotate(-22deg); color: rgba(176, 0, 32, .18); font-size: 76px; font-weight: 800; text-align: center; pointer-events: none; }
        .cancel-note { margin-top: 12px; color: #b00020; font-weight: 700; }
        @media print { body { background: #fff; } .no-print { display: none !important; } .sheet { margin: 0; padding: 0; width: auto; min-height: 0; } }
    </style>
</head>
<body>
@php
    $money = fn ($amount) => number_format((float) $amount);
    $quantity = fn ($amount) => rtrim(rtrim(number_format((float) $amount, 3), '0'), '.');
@endphp
<div class="toolbar no-print">
    @if ($canPrint)
        <button type="button" id="printBtn">Print purchase document</button>
    @else
        <span>You may view this document, but do not have permission to print it.</span>
    @endif
    <span style="flex: 1"></span>
    <button type="button" onclick="window.print()">Save as PDF</button>
    <a href="{{ route('purchases.show', $purchase) }}">Back</a>
</div>
<main class="sheet">
    @if ($purchase->isCancelled())<div class="cancelled">CANCELLED</div>@endif
    <header class="company">
        @if ($co['logoUrl'])<img src="{{ $co['logoUrl'] }}" alt="{{ $co['name'] }}" style="max-height:72px;max-width:100%">@else<h1>{{ $co['name'] }}</h1>@endif
        @if ($co['tagline'])<p><strong>{{ $co['tagline'] }}</strong></p>@endif
        @if ($co['address'])<p>{{ $co['address'] }}</p>@endif
        <p>
            @if ($co['phone']){{ $co['phone'] }}@endif
            @if ($co['phone'] && $co['email']) · @endif
            @if ($co['email']){{ $co['email'] }}@endif
        </p>
        @if ($co['ntn'] || $co['strn'])
            <p>@if ($co['ntn'])NTN {{ $co['ntn'] }}@endif @if ($co['ntn'] && $co['strn']) · @endif @if ($co['strn'])STRN {{ $co['strn'] }}@endif</p>
        @endif
    </header>

    <section class="heading">
        <div>
            <h2>Purchase document</h2>
            <div><strong>Our reference:</strong> {{ $purchase->purchase_no }}</div>
            @if ($purchase->supplier_ref)<div><strong>Supplier invoice:</strong> {{ $purchase->supplier_ref }}</div>@endif
            <div><strong>Status:</strong> {{ ucfirst($purchase->status) }}</div>
        </div>
        <div class="right">
            <div><strong>Purchase date:</strong> {{ $purchase->purchase_date->format('d-M-Y') }}</div>
            @if ($purchase->due_date)<div><strong>Due date:</strong> {{ $purchase->due_date->format('d-M-Y') }}</div>@endif
            @if ($purchase->invoicePrint)<div class="muted">Printed {{ $purchase->invoicePrint->printed_at->format('d-M-Y H:i') }}</div>@endif
        </div>
    </section>

    <section class="supplier">
        <h3>Supplier</h3>
        <p><strong>{{ $purchase->supplier?->company ?: $purchase->supplier?->name ?: 'Walk-in / cash purchase' }}</strong></p>
        @if ($purchase->supplier?->contact_person)<p>{{ $purchase->supplier->contact_person }}</p>@endif
        @if ($purchase->supplier?->address)<p>{{ $purchase->supplier->address }}</p>@endif
        <p>
            @if ($purchase->supplier?->phone){{ $purchase->supplier->phone }}@endif
            @if ($purchase->supplier?->phone && $purchase->supplier?->email) · @endif
            @if ($purchase->supplier?->email){{ $purchase->supplier->email }}@endif
        </p>
        @if ($purchase->supplier?->ntn || $purchase->supplier?->strn)
            <p>@if ($purchase->supplier?->ntn)NTN {{ $purchase->supplier->ntn }}@endif @if ($purchase->supplier?->ntn && $purchase->supplier?->strn) · @endif @if ($purchase->supplier?->strn)STRN {{ $purchase->supplier->strn }}@endif</p>
        @endif
    </section>

    <table class="items">
        <thead><tr><th>#</th><th>Item</th><th class="right">Quantity</th><th class="right">Unit cost</th><th class="right">Discount</th><th class="right">Tax</th><th class="right">Line total</th></tr></thead>
        <tbody>
        @foreach ($purchase->items as $index => $item)
            <tr>
                <td>{{ $index + 1 }}</td>
                <td>{{ $item->product->code }} · {{ $item->product->name }}</td>
                <td class="right">{{ $quantity($item->qty) }} {{ $item->product->unit?->short_name }}</td>
                <td class="right">{{ $money($item->unit_cost) }}</td>
                <td class="right">{{ $money($item->discount) }}</td>
                <td class="right">{{ $money($item->tax_amount) }} ({{ rtrim(rtrim($item->tax_rate, '0'), '.') }}%)</td>
                <td class="right">{{ $money($item->line_total) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr><td>Subtotal</td><td class="right">{{ $money($purchase->subtotal) }}</td></tr>
        <tr><td>Discount</td><td class="right">−{{ $money($purchase->discount_total) }}</td></tr>
        <tr><td>Tax</td><td class="right">{{ $money($purchase->tax_total) }}</td></tr>
        <tr><td>Total</td><td class="right">{{ $money($purchase->total) }}</td></tr>
    </table>

    <section class="payment">
        <strong>Payment status:</strong> {{ ucfirst($summary['status']) }} ·
        <strong>Paid:</strong> {{ $money($summary['paid'] / 100) }} ·
        <strong>Returned:</strong> {{ $money($summary['returned'] / 100) }} ·
        <strong>Balance:</strong> {{ $money($summary['balance'] / 100) }}
        @if ($purchase->paidFromAccount && (float) $purchase->paid_now)
            <div class="muted">Paid at purchase from {{ $purchase->paidFromAccount->name }}</div>
        @endif
    </section>

    @if ($purchase->isCancelled())
        <p class="cancel-note">Cancelled on {{ $purchase->cancelled_at?->format('d-M-Y') }}@if ($purchase->cancel_reason) — {{ $purchase->cancel_reason }}@endif</p>
    @endif
    @if ($purchase->notes)<p><strong>Notes:</strong> {{ $purchase->notes }}</p>@endif
</main>

@if ($canPrint)
<script>
document.getElementById('printBtn').addEventListener('click', async function () {
    try {
        const response = await fetch(@json(route('purchases.invoice.printed', $purchase)), {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content
            }
        });
        const result = await response.json();
        if (!response.ok) {
            alert(result.message || 'The purchase document could not be recorded for printing.');
            return;
        }
        window.print();
    } catch (error) {
        alert('Could not record the print. Please try again.');
    }
});
</script>
@endif
</body>
</html>
