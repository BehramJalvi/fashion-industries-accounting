<?php

namespace App\Services\Reports;

use App\Models\JournalEntry;
use Carbon\Carbon;

class DayBookService
{
    /** All journal entries in the range, newest day last, with a count and amount per transaction type. */
    public function build(string $from, string $to, ?string $voucher = null): array
    {
        $dayAfterTo = Carbon::parse($to)->addDay()->toDateString();
        $entries = JournalEntry::withCount('lines')->where('entry_date', '>=', $from)->where('entry_date', '<', $dayAfterTo)
            ->when($voucher, fn ($q, $v) => $q->where('voucher_type', $v))->orderBy('entry_date')->orderBy('id')->limit(5000)->get();

        $summary = $entries->groupBy('voucher_type')->map(fn ($g) => ['count' => $g->count(), 'amount' => $g->sum(fn ($e) => (int) round((float) $e->total_amount * 100))])->sortKeys();

        return ['entries' => $entries, 'summary' => $summary];
    }
}
