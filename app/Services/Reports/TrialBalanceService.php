<?php

namespace App\Services\Reports;

use App\Models\JournalEntryLine;
use App\Support\Money;

/** Trial balance straight from the journal: opening, movement in the period, and closing, each as a debit or credit. */
class TrialBalanceService
{
    /**
     * @return array{rows:array<int,array>, totals:array<string,int>, balanced:bool}  all money in paisa
     */
    public function build(string $from, string $to): array
    {
        $data = JournalEntryLine::query()
            ->join('journal_entries as e', 'e.id', '=', 'journal_entry_lines.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'journal_entry_lines.account_id')
            ->where('e.entry_date', '<=', $to)
            ->selectRaw('a.id as aid, a.code, a.name, a.type,
                SUM(CASE WHEN e.entry_date < ? THEN journal_entry_lines.debit ELSE 0 END) AS od,
                SUM(CASE WHEN e.entry_date < ? THEN journal_entry_lines.credit ELSE 0 END) AS oc,
                SUM(CASE WHEN e.entry_date >= ? THEN journal_entry_lines.debit ELSE 0 END) AS pd,
                SUM(CASE WHEN e.entry_date >= ? THEN journal_entry_lines.credit ELSE 0 END) AS pc', [$from, $from, $from, $from])
            ->groupBy('a.id', 'a.code', 'a.name', 'a.type')->orderBy('a.code')->get();

        $rows = [];
        $t = ['open_dr' => 0, 'open_cr' => 0, 'period_dr' => 0, 'period_cr' => 0, 'close_dr' => 0, 'close_cr' => 0];

        foreach ($data as $r) {
            $od = Money::toCents($r->od); $oc = Money::toCents($r->oc); $pd = Money::toCents($r->pd); $pc = Money::toCents($r->pc);
            $open = $od - $oc;
            $close = $open + $pd - $pc;
            $row = [
                'code' => $r->code, 'name' => $r->name, 'type' => $r->type,
                'open_dr' => max($open, 0), 'open_cr' => max(-$open, 0), 'period_dr' => $pd, 'period_cr' => $pc,
                'close_dr' => max($close, 0), 'close_cr' => max(-$close, 0),
            ];
            $rows[] = $row;
            foreach ($t as $k => $_) {
                $t[$k] += $row[$k];
            }
        }

        return ['rows' => $rows, 'totals' => $t, 'balanced' => $t['close_dr'] === $t['close_cr'] && $t['open_dr'] === $t['open_cr'] && $t['period_dr'] === $t['period_cr']];
    }
}
