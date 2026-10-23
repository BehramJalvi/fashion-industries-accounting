@extends('layouts.app')
@section('title', 'New journal entry')
@section('content')
@php
    $rows = old('lines', [[], []]);
@endphp
<form method="POST" action="{{ route('journal.store') }}" id="journalForm">
    @csrf
    <div class="card mb-3"><div class="card-body row g-3">
        <div class="col-md-3"><label class="form-label">Date</label>
            <input type="date" name="entry_date" class="form-control" value="{{ old('entry_date', now()->toDateString()) }}" required></div>
        <div class="col-md-3"><label class="form-label">Reference</label>
            <input name="reference" class="form-control" value="{{ old('reference') }}" maxlength="60"></div>
        <div class="col-md-6"><label class="form-label">Description</label>
            <input name="description" class="form-control" value="{{ old('description') }}" required maxlength="500"></div>
    </div></div>

    <div class="card">
        <table class="table align-middle mb-0" id="lines">
            <thead class="table-light"><tr><th style="width:30%">Account</th><th style="width:20%">Party</th><th>Narration</th>
                <th style="width:130px">Debit</th><th style="width:130px">Credit</th><th style="width:40px"></th></tr></thead>
            <tbody>
            @foreach ($rows as $i => $row)
                <tr class="line">
                    <td><select name="lines[{{ $i }}][account_id]" class="form-select form-select-sm acct" required>
                        <option value="">Select account…</option>
                        @foreach ($accounts as $a)
                            <option value="{{ $a->id }}" data-party="{{ $a->party_type }}" @selected(($row['account_id'] ?? null) == $a->id)>{{ $a->code }} · {{ $a->name }}</option>
                        @endforeach</select></td>
                    <td><select name="lines[{{ $i }}][party]" class="form-select form-select-sm party" disabled>
                        <option value="">—</option>
                        @foreach ($parties as $type => $list)
                            @foreach ($list as $p)
                                <option value="{{ $type }}:{{ $p->id }}" data-type="{{ $type }}" @selected(($row['party'] ?? null) === "$type:{$p->id}")>{{ $p->name }}</option>
                            @endforeach
                        @endforeach</select></td>
                    <td><input name="lines[{{ $i }}][narration]" class="form-control form-control-sm" value="{{ $row['narration'] ?? '' }}"></td>
                    <td><input name="lines[{{ $i }}][debit]" class="form-control form-control-sm text-end debit" inputmode="decimal" value="{{ $row['debit'] ?? '' }}"></td>
                    <td><input name="lines[{{ $i }}][credit]" class="form-control form-control-sm text-end credit" inputmode="decimal" value="{{ $row['credit'] ?? '' }}"></td>
                    <td><button type="button" class="btn btn-sm btn-outline-danger remove" title="Remove line">&times;</button></td>
                </tr>
            @endforeach
            </tbody>
            <tfoot class="table-light">
                <tr><td colspan="3"><button type="button" class="btn btn-sm btn-outline-secondary" id="addLine"><i class="bi bi-plus"></i> Add line</button></td>
                    <td class="text-end fw-semibold" id="sumDebit">0.00</td><td class="text-end fw-semibold" id="sumCredit">0.00</td><td></td></tr>
                <tr><td colspan="6" class="text-end" id="diff"></td></tr>
            </tfoot>
        </table>
    </div>

    <div class="mt-3">
        <a href="{{ route('journal.index') }}" class="btn btn-light">Cancel</a>
        <button class="btn btn-primary">Post entry</button>
        <span class="text-muted small ms-2">Posting is immediate. A mistake is corrected by cancelling, which posts a reversing entry.</span>
    </div>
</form>
@endsection

@push('scripts')
<script>
(function () {
    const body = document.querySelector('#lines tbody');

    function syncParty(row) {
        const partyType = row.querySelector('.acct').selectedOptions[0]?.dataset.party || '';
        const sel = row.querySelector('.party');
        sel.disabled = partyType === '';
        [...sel.options].forEach(o => { o.hidden = o.dataset.type && o.dataset.type !== partyType; });
        if (sel.disabled || (sel.selectedOptions[0]?.dataset.type && sel.selectedOptions[0].dataset.type !== partyType)) sel.value = '';
        sel.required = partyType !== '';
    }
    function totals() {
        let d = 0, c = 0;
        body.querySelectorAll('.line').forEach(r => {
            d += Math.round((parseFloat(r.querySelector('.debit').value) || 0) * 100);
            c += Math.round((parseFloat(r.querySelector('.credit').value) || 0) * 100);
        });
        document.getElementById('sumDebit').textContent = (d / 100).toFixed(2);
        document.getElementById('sumCredit').textContent = (c / 100).toFixed(2);
        const diff = document.getElementById('diff');
        diff.textContent = d === c ? (d ? 'Balanced' : '') : 'Out of balance by ' + (Math.abs(d - c) / 100).toFixed(2);
        diff.className = 'text-end ' + (d === c ? 'text-success' : 'text-danger');
    }
    function renumber() {
        body.querySelectorAll('.line').forEach((r, i) =>
            r.querySelectorAll('[name]').forEach(el => el.name = el.name.replace(/lines\[\d+\]/, 'lines[' + i + ']')));
    }

    body.addEventListener('change', e => { if (e.target.classList.contains('acct')) syncParty(e.target.closest('tr')); });
    body.addEventListener('input', totals);
    body.addEventListener('click', e => {
        if (e.target.closest('.remove') && body.querySelectorAll('.line').length > 2) {
            e.target.closest('tr').remove(); renumber(); totals();
        }
    });
    document.getElementById('addLine').addEventListener('click', () => {
        const clone = body.querySelector('.line').cloneNode(true);
        clone.querySelectorAll('input').forEach(i => i.value = '');
        clone.querySelectorAll('select').forEach(s => s.selectedIndex = 0);
        body.appendChild(clone); renumber(); syncParty(clone);
    });

    body.querySelectorAll('.line').forEach(syncParty);
    totals();
})();
</script>
@endpush
