<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $co['title'] }} {{ $sale->invoice_no }}</title>
    <style>
        @page { size: A4; margin: 12mm; }
        * { box-sizing: border-box; }
        body { font-family: Arial, Helvetica, sans-serif; font-size: 12px; color: #000; margin: 0; background: #e9ecef; }
        .toolbar { background: #1f2a44; color: #fff; padding: 10px 16px; display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
        .toolbar a, .toolbar button { background: #fff; color: #1f2a44; border: 0; border-radius: 4px; padding: 6px 12px; font-size: 13px; text-decoration: none; cursor: pointer; }
        .toolbar .spacer { flex: 1; }
        .toolbar .note { font-size: 12px; opacity: .85; }
        .sheet { position: relative; width: 190mm; min-height: 270mm; margin: 14px auto; padding: 8mm 6mm; background: #fff; }
        .brand { text-align: center; }
        .brand img { max-height: 90px; max-width: 100%; margin-top: 40px;  }
        .brand .name { font-size: 28px; font-weight: 700; color: #1c7ec0; letter-spacing: .5px; }
        .brand .tagline { font-weight: 700; color: #1c7ec0; font-size: 13px; margin-top: 2px; }
        .brand .addr { font-family: "Times New Roman", serif; font-size: 12px; margin-top: 2px; }
        .brand .address { margin-bottom: 40px; }
        .meta { display: flex; justify-content: space-between; margin-top: 16px; }
        .copy { font-weight: 700; font-size: 12px; }
        .title { font-size: 15px; font-weight: 700; font-style: italic; text-decoration: underline; margin: 2px 0 8px 90px; }
        table.ids td, table.ids th { text-align: left; padding: 1px 12px 1px 0; font-size: 12px; vertical-align: top; }
        table.ids th { font-weight: 700; white-space: nowrap; }
        td.inv { font-weight: 700; text-decoration: underline; }
        .right { text-align: right; width: 48%; }
        .date { font-weight: 700; font-size: 11px; margin-bottom: 8px; }
        table.ogp { margin-left: auto; border: 2px solid #000; border-collapse: collapse; width: 100%; }
        table.ogp td { border: 1px solid #000; padding: 3px 6px; font-weight: 700; text-align: center; }
        table.buyer { margin-top: 14px; }
        table.buyer th, table.buyer td { text-align: left; padding: 0 14px 0 0; vertical-align: top; font-size: 12px; }
        table.buyer th { font-weight: 700; white-space: nowrap; width: 125px; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 6px; border: 2px solid #000; }
        table.items th { color: #1c9bd8; font-size: 10.5px; border: 1px solid #000; padding: 3px 4px; text-align: center; }
        table.items td { border: 1px solid #000; padding: 5px 5px; font-size: 11.5px; font-weight: 700; }
        table.items tr.blank-row td { height: 21px; border-color: #bbb; font-weight: 400; }
        table.items tr.total td { border-top: 2px solid #000; border-bottom: 2px solid #000; font-weight: 700; }
        .c { text-align: center; } .r { text-align: right; }
        .pay { margin: 10px 0 0 auto; border-collapse: collapse; min-width: 55mm; }
        .pay td { padding: 2px 8px; font-size: 12px; } .pay td:last-child { text-align: right; font-weight: 700; }
        .footer { margin-top: 90px; color: #1c9bd8; font-weight: 700; font-size: 14px; text-decoration: underline; padding-left: 20px; }
        .cancel-note { margin-top: 10px; color: #b00020; font-weight: 700; }
        .watermark { position: absolute; top: 38%; left: 8%; right: 8%; text-align: center; font-size: 90px; font-weight: 800; color: rgba(176, 0, 32, .18); transform: rotate(-24deg); pointer-events: none; letter-spacing: 6px; }
        @media print { body { background: #fff; } .no-print { display: none !important; } .sheet { margin: 0; padding: 0; width: auto; min-height: 0; } }
    </style>
</head>
<body>
@php
    $f = fn ($v) => number_format((float) $v);
    $q = fn ($v) => rtrim(rtrim(number_format((float) $v, 3), '0'), '.');
    $rate = fn ($v) => rtrim(rtrim((string) $v, '0'), '.') ?: '0';
    $hasDiscount = (float) $sale->discount_total > 0;
    $exTax = (float) $sale->subtotal - (float) $sale->discount_total;
    $balance = (float) $sale->total - (float) $sale->received_now;
@endphp

<div class="toolbar no-print">
    @if ($canPrint)<button type="button" id="printBtn">Print {{ strtolower($copy) }}</button>@else<span class="note">View only (you do not have permission to print)</span>@endif
    @if ($copy !== 'cancelled' && $sale->isPosted())
        @if ($copy !== 'duplicate')<a href="{{ route('sales.invoice', [$sale, 'copy' => 'duplicate']) }}">Show duplicate</a>@endif
        @if ($copy !== 'original')@can('invoices.reprint')<a href="{{ route('sales.invoice', [$sale, 'copy' => 'original']) }}">Show original</a>@endcan @endif
    @endif
    <span class="spacer"></span>
    <span class="note">Use "Save as PDF" in the print dialog for a PDF file.</span>
    <a href="{{ route('sales.show', $sale) }}">Back</a>
</div>

<div class="sheet">
    @if ($copy === 'cancelled')<div class="watermark">CANCELLED</div>@endif

    <div  class="brand"  >
        @if ($co['logoUrl'])<img src="{{ $co['logoUrl'] }}" alt="{{ $co['name'] }}" loading="eager" decoding="sync">@else<div class="name">{{ $co['name'] }}</div>@endif
        @if ($co['tagline'])<div class="tagline">{{ $co['tagline'] }}</div>@endif
        @if ($co['address'])<div class="addr address"><strong>Address:</strong> {{ $co['address'] }}</div>@endif
        @if ($co['phone'] || $co['email'])<div class="addr">@if ($co['phone'])Tel: {{ $co['phone'] }}@endif @if ($co['phone'] && $co['email']) · @endif @if ($co['email'])Email: {{ $co['email'] }}@endif</div>@endif
    </div>

    <div class="meta">
        <div>
            <div class="copy">{{ strtoupper($copy) }}:</div>
            <div class="title">{{ $co['title'] }}</div>
            <table class="ids">
                <tr><th>INVOICE NO :</th><td class="inv">{{ $sale->invoice_no }}</td></tr>
                <tr><th>NTN NO.</th><td>{{ $co['ntn'] }}</td></tr>
                <tr><th>SALES TAX REG NO.</th><td>{{ $co['strn'] }}</td></tr>
            </table>
        </div>
        <div class="right">
            <div class="date">DATE : {{ $sale->sale_date->format('d/m/Y') }}</div>
            @if ($sale->gate_pass_no)<table class="ogp"><tr><td style="width:40%">OGP</td><td>{{ $sale->gate_pass_no }}</td></tr></table>@endif
        </div>
    </div>

    <table class="buyer">
        <tr><th>BUYER'S NAME :</th><td>{{ $sale->buyer_name }}</td></tr>
        <tr><th>ADDRESS :</th><td>{{ $sale->buyer_address }}</td></tr>
        <tr><th>NTN No.</th><td>{{ $sale->buyer_ntn }}</td></tr>
        <tr><th>SALES TAX REG NO.</th><td>{{ $sale->buyer_strn }}</td></tr>
    </table>

    <table class="items">
        <thead><tr>
            <th style="width:5%">Sr #</th><th>Description</th><th style="width:9%">Quantity</th><th style="width:9%">Unit Rate</th><th style="width:8%">Sales Tax</th>
            @if ($hasDiscount)<th style="width:9%">Discount</th>@endif
            <th style="width:13%">Value Ex-Sales Tax</th><th style="width:12%">Sales Tax Payable</th><th style="width:12%">Value Inc. Sales Tax</th>
        </tr></thead>
        <tbody>
        @foreach ($sale->items as $n => $i)
            <tr>
                <td class="c">{{ $n + 1 }}</td><td class="c">{{ $i->description ?: $i->product->name }}</td><td class="c">{{ $q($i->qty) }}</td><td class="c">{{ $f($i->unit_price) }}</td><td class="c">{{ $rate($i->tax_rate) }}%</td>
                @if ($hasDiscount)<td class="r">{{ (float) $i->discount ? $f($i->discount) : '' }}</td>@endif
                <td class="r">{{ $f((float) $i->line_total - (float) $i->tax_amount) }}</td><td class="r">{{ $f($i->tax_amount) }}</td><td class="r">{{ $f($i->line_total) }}</td>
            </tr>
        @endforeach
        @for ($k = $sale->items->count(); $k < $co['minRows']; $k++)
            <tr class="blank-row"><td>&nbsp;</td><td></td><td></td><td></td><td></td>@if ($hasDiscount)<td></td>@endif<td></td><td></td><td></td></tr>
        @endfor
            <tr class="total">
                <td></td><td class="c">TOTAL</td><td class="c">{{ $q($sale->items->sum('qty')) }}</td><td></td><td></td>
                @if ($hasDiscount)<td class="r">{{ $f($sale->discount_total) }}</td>@endif
                <td class="r">{{ $f($exTax) }}</td><td class="r">{{ $f($sale->tax_total) }}</td><td class="r">{{ $f($sale->total) }}</td>
            </tr>
        </tbody>
    </table>

    @if ($co['showPayment'] && (float) $sale->received_now > 0)
        <table class="pay">
            <tr><td>Received</td><td>{{ $f($sale->received_now) }}</td></tr>
            <tr><td>Balance</td><td>{{ $f($balance) }}</td></tr>
        </table>
    @endif

    @if ($copy === 'cancelled')
        <div class="cancel-note">This invoice was cancelled on {{ $sale->cancelled_at?->format('d/m/Y') }}@if ($sale->cancel_reason) — {{ $sale->cancel_reason }}@endif</div>
    @endif

    @if ($co['footer'])<div class="footer">{{ $co['footer'] }}</div>@endif
</div>

@if ($canPrint)
<script>
document.getElementById('printBtn').addEventListener('click', async function () {
    try {
        const res = await fetch(@json(route('sales.invoice.printed', $sale)), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
            body: JSON.stringify({ copy: @json($copy) }),
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) { alert(data.message || 'This copy cannot be printed.'); return; }
    } catch (e) { alert('Could not record the print. Please try again.'); return; }
    const logo = document.querySelector('.brand img');
    if (logo) {
        try {
            await logo.decode();
        } catch (e) {
            alert('The company logo could not be loaded. Check the logo in Settings before printing.');
            return;
        }
    }
    window.print();
});
</script>
@endif
</body>
</html>
