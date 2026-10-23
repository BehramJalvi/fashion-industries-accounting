<div class="card mt-4"><div class="card-body">
    <h6>Opening balance</h6>
    @if ($openingEntry)
        @php
            $line = $openingEntry->lines->firstWhere('party_type', $cfg['type']);
        @endphp
        <p class="mb-2">PKR {{ number_format((float) $openingEntry->total_amount, 2) }}
            <span class="badge text-bg-light border">{{ (float) $line->debit > 0 ? 'debit' : 'credit' }} balance</span>
            as of {{ $openingEntry->entry_date->format('d-M-Y') }} ·
            <a href="{{ route('journal.show', $openingEntry) }}">{{ $openingEntry->entry_no }}</a></p>
        @can('journal.cancel')
            <form method="POST" action="{{ route('parties.opening.destroy', [$cfg['type'], $party->id]) }}" class="row g-2"
                  onsubmit="return confirm('Cancel this opening balance? A reversing entry will be posted.')">
                @csrf @method('DELETE')
                <div class="col-md-6"><input name="reason" class="form-control form-control-sm" placeholder="Reason (optional)" maxlength="200"></div>
                <div class="col-auto"><button class="btn btn-sm btn-outline-danger">Cancel opening balance</button></div>
            </form>
        @endcan
    @else
        @can('journal.post')
            <form method="POST" action="{{ route('parties.opening.store', [$cfg['type'], $party->id]) }}" class="row g-3">
                @csrf
                <div class="col-md-3"><label class="form-label">Amount (PKR)</label><input name="opening_amount" class="form-control text-end" inputmode="decimal" required></div>
                <div class="col-md-5"><label class="form-label">Direction</label>
                    <select name="opening_side" class="form-select">@foreach ($cfg['labels']['sides'] as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach</select></div>
                <div class="col-md-2"><label class="form-label">As of</label><input type="date" name="opening_date" class="form-control" value="{{ now()->toDateString() }}" required></div>
                <div class="col-md-2 d-flex align-items-end"><button class="btn btn-primary w-100">Record</button></div>
            </form>
        @else
            <p class="text-muted mb-0">No opening balance recorded. An administrator can add one.</p>
        @endcan
    @endif
</div></div>
