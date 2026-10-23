{{-- Open invoices (receipt) or bills (payment) with an "apply" box each. Expects: $open, $kind, optional $fillCents (amount to spread when there is no #amount box) --}}
@php
    $noun = $kind === 'receipt' ? 'invoices' : 'bills';
@endphp
<div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span class="fw-semibold">Open {{ $noun }}</span>
        @if ($open->isNotEmpty())<button type="button" class="btn btn-sm btn-outline-secondary" id="fillOldest">Fill oldest first</button>@endif
    </div>
    @if ($open->isEmpty())
        <div class="card-body text-muted">Nothing is outstanding. Money entered here stays on the account (advance) and can be applied to {{ $noun }} later.</div>
    @else
    <div class="table-responsive"><table class="table table-sm align-middle mb-0" id="openItems">
        <thead class="table-light"><tr><th>{{ $kind === 'receipt' ? 'Invoice' : 'Bill' }}</th><th>Date</th><th class="text-end">Total</th><th class="text-end">Outstanding</th><th style="width:150px">Apply</th></tr></thead>
        <tbody>
        @foreach ($open as $row)
            @php
                $doc = $row['model'];
            @endphp
            <tr data-balance="{{ $row['balance'] }}">
                <td>{{ $row['label'] }}</td>
                <td>{{ ($doc->sale_date ?? $doc->purchase_date)->format('d-M-Y') }}</td>
                <td class="text-end">{{ number_format((float) $doc->total, 2) }}</td>
                <td class="text-end">{{ number_format($row['balance'] / 100, 2) }}</td>
                <td><input name="alloc[{{ $doc->id }}]" class="form-control form-control-sm text-end apply" inputmode="decimal" value="{{ old('alloc.' . $doc->id) }}"></td>
            </tr>
        @endforeach
        </tbody>
        <tfoot class="table-light"><tr><td colspan="4" class="text-end">Applied</td><td class="text-end fw-semibold" id="appliedTotal">0.00</td></tr></tfoot>
    </table></div>
    @endif
</div>

@push('scripts')
<script>
(function () {
    const table = document.getElementById('openItems');
    if (!table) return;
    const amountBox = document.getElementById('amount');
    const fixed = {{ (int) ($fillCents ?? 0) }};
    const num = v => parseFloat(v) || 0;
    const total = () => [...table.querySelectorAll('.apply')].reduce((s, i) => s + Math.round(num(i.value) * 100), 0);
    const show = () => { document.getElementById('appliedTotal').textContent = (total() / 100).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };

    document.getElementById('fillOldest').addEventListener('click', () => {
        let left = amountBox ? Math.round(num(amountBox.value) * 100) : fixed;
        table.querySelectorAll('tbody tr').forEach(r => {
            const take = Math.min(left, parseInt(r.dataset.balance, 10));
            r.querySelector('.apply').value = take > 0 ? (take / 100).toFixed(2) : '';
            left -= Math.max(take, 0);
        });
        show();
    });
    table.addEventListener('input', show);
    show();
})();
</script>
@endpush
