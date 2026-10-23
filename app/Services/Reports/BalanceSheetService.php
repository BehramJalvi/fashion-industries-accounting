<?php

namespace App\Services\Reports;

use App\Models\JournalEntryLine;
use App\Support\Money;

/**
 * Balance sheet as at a date, from the journal. There is no year-end close yet, so the profit of all periods up to the date is shown on
 * its own line under equity ("Profit / (loss) not yet closed to Retained Earnings"); Assets = Liabilities + Equity always holds.
 */
class BalanceSheetService
{
    /** @return array<string, mixed> money in paisa */
    public function asAt(string $date): array
    {
        $rows = JournalEntryLine::query()
            ->join('journal_entries as e', 'e.id', '=', 'journal_entry_lines.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'journal_entry_lines.account_id')
            ->where('e.entry_date', '<=', $date)
            ->selectRaw('a.code, a.name, a.type, a.sub_type, SUM(journal_entry_lines.debit) as d, SUM(journal_entry_lines.credit) as c')
            ->groupBy('a.code', 'a.name', 'a.type', 'a.sub_type')->orderBy('a.code')->get();

        $assets = $liabilities = $equity = [];
        $profit = 0;
        foreach ($rows as $r) {
            $d = Money::toCents($r->d);
            $c = Money::toCents($r->c);
            $line = ['code' => $r->code, 'name' => $r->name];

            match ($r->type) {
                'asset'     => $assets[] = $line + ['amount' => $d - $c],
                'liability' => $liabilities[] = $line + ['amount' => $c - $d],
                'equity'    => $equity[] = $line + ['amount' => $c - $d],
                default     => $profit += $c - $d,        // revenue is credit-natured; cost of sales and expenses reduce it
            };
        }

        $nonZero = fn (array $lines) => array_values(array_filter($lines, fn ($l) => $l['amount'] !== 0));
        $assets = $nonZero($assets);
        $liabilities = $nonZero($liabilities);
        $equity = $nonZero($equity);

        $totalAssets = array_sum(array_column($assets, 'amount'));
        $totalLiabilities = array_sum(array_column($liabilities, 'amount'));
        $totalEquity = array_sum(array_column($equity, 'amount')) + $profit;

        return [
            'assets' => $assets, 'liabilities' => $liabilities, 'equity' => $equity, 'profit' => $profit,
            'total_assets' => $totalAssets, 'total_liabilities' => $totalLiabilities, 'total_equity' => $totalEquity,
            'difference' => $totalAssets - $totalLiabilities - $totalEquity, 'balanced' => $totalAssets === $totalLiabilities + $totalEquity,
        ];
    }
}
