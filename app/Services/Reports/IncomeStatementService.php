<?php

namespace App\Services\Reports;

use App\Models\JournalEntryLine;
use App\Support\Money;

/**
 * Profit & Loss from the journal for a period:
 *   Net sales = gross sales - returns - discounts (+ other income)
 *   Gross profit = net sales - cost of goods sold (COGS, inventory gain/loss, processing charges)
 *   Net profit = gross profit - operating expenses
 */
class IncomeStatementService
{
    /** @return array<string, mixed> money in paisa */
    public function build(string $from, string $to): array
    {
        $accounts = JournalEntryLine::query()
            ->join('journal_entries as e', 'e.id', '=', 'journal_entry_lines.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'journal_entry_lines.account_id')
            ->whereDate('e.entry_date', '>=', $from)
            ->whereDate('e.entry_date', '<=', $to)
            ->where('e.voucher_type', '!=', 'year_end_close')
            ->whereNotExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('journal_entries as closing_entries')
                ->whereColumn('closing_entries.id', 'e.reverses_entry_id')
                ->where('closing_entries.voucher_type', 'year_end_close'))
            ->whereIn('a.type', ['revenue', 'cogs', 'expense'])
            ->selectRaw('a.code, a.name, a.type, a.sub_type, SUM(journal_entry_lines.debit) as d, SUM(journal_entry_lines.credit) as c')
            ->groupBy('a.code', 'a.name', 'a.type', 'a.sub_type')->orderBy('a.code')->get();

        $sales = $deductions = $otherIncome = $cogs = $expenses = [];
        foreach ($accounts as $a) {
            $d = Money::toCents($a->d);
            $c = Money::toCents($a->c);
            $line = ['code' => $a->code, 'name' => $a->name];

            if ($a->type === 'revenue') {
                if ($a->sub_type === 'sales') {
                    $sales[] = $line + ['amount' => $c - $d];
                } elseif (in_array($a->sub_type, ['sales_return', 'discount'], true)) {
                    $deductions[] = $line + ['amount' => $d - $c];
                } else {
                    $otherIncome[] = $line + ['amount' => $c - $d];
                }
            } elseif ($a->type === 'cogs') {
                $cogs[] = $line + ['amount' => $d - $c];
            } else {
                $expenses[] = $line + ['amount' => $d - $c];
            }
        }

        $sum = fn (array $lines) => array_sum(array_column($lines, 'amount'));
        $grossSales = $sum($sales);
        $totalDeductions = $sum($deductions);
        $other = $sum($otherIncome);
        $netSales = $grossSales - $totalDeductions + $other;
        $totalCogs = $sum($cogs);
        $grossProfit = $netSales - $totalCogs;
        $totalExpenses = $sum($expenses);

        return [
            'sales' => $sales, 'deductions' => $deductions, 'other_income' => $otherIncome, 'cogs' => $cogs, 'expenses' => $expenses,
            'gross_sales' => $grossSales, 'total_deductions' => $totalDeductions, 'other' => $other, 'net_sales' => $netSales,
            'total_cogs' => $totalCogs, 'gross_profit' => $grossProfit, 'total_expenses' => $totalExpenses,
            'net_profit' => $grossProfit - $totalExpenses,
            'margin' => $netSales > 0 ? round($grossProfit / $netSales * 100, 1) : null,
        ];
    }
}
