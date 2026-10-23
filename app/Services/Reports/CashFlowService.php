<?php

namespace App\Services\Reports;

use App\Models\Account;
use App\Models\JournalEntryLine;
use App\Support\Money;

/**
 * Cash flow (direct method): every movement through the cash and bank accounts, grouped by the kind of transaction.
 * Transfers between your own cash and bank accounts net to zero.
 */
class CashFlowService
{
    public const LABELS = [
        'sale' => 'Cash received with sales', 'receipt' => 'Receipts from customers', 'purchase' => 'Cash paid with purchases', 'payment' => 'Payments to suppliers and employees',
        'expense' => 'Expenses paid', 'sales_return' => 'Refunds to customers', 'purchase_return' => 'Refunds from suppliers', 'owner' => 'Owner capital and drawings',
        'transfer' => 'Transfers between cash and bank', 'journal' => 'Manual journal entries', 'opening' => 'Opening balances', 'reversal' => 'Cancellations (reversals)', 'adjustment' => 'Stock adjustments',
    ];

    /** @return array{opening:int, closing:int, rows:array<int,array>, in:int, out:int, accounts:array<int,array>} */
    public function build(string $from, string $to): array
    {
        $ids = Account::whereIn('sub_type', ['cash', 'bank'])->pluck('id')->all();
        $base = fn () => JournalEntryLine::query()
            ->join('journal_entries as e', 'e.id', '=', 'journal_entry_lines.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'journal_entry_lines.account_id')->whereIn('journal_entry_lines.account_id', $ids);

        $net = fn ($r) => Money::toCents($r->d) - Money::toCents($r->c);

        $perAccount = $base()->where('e.entry_date', '<', $from)->selectRaw('a.id as aid, SUM(journal_entry_lines.debit) as d, SUM(journal_entry_lines.credit) as c')->groupBy('a.id')->get()->keyBy('aid');
        $moves = $base()->whereBetween('e.entry_date', [$from, $to])->selectRaw('e.voucher_type, SUM(journal_entry_lines.debit) as d, SUM(journal_entry_lines.credit) as c')
            ->groupBy('e.voucher_type')->get();
        $perAccountPeriod = $base()->whereBetween('e.entry_date', [$from, $to])->selectRaw('a.id as aid, SUM(journal_entry_lines.debit) as d, SUM(journal_entry_lines.credit) as c')->groupBy('a.id')->get()->keyBy('aid');

        $rows = [];
        $in = $out = 0;
        foreach ($moves as $m) {
            $d = Money::toCents($m->d);
            $c = Money::toCents($m->c);
            $rows[] = ['type' => $m->voucher_type, 'label' => self::LABELS[$m->voucher_type] ?? ucfirst(str_replace('_', ' ', $m->voucher_type)), 'in' => $d, 'out' => $c, 'net' => $d - $c];
            $in += $d;
            $out += $c;
        }
        usort($rows, fn ($a, $b) => array_search($a['type'], array_keys(self::LABELS)) <=> array_search($b['type'], array_keys(self::LABELS)));

        $accounts = [];
        $opening = 0;
        foreach (Account::whereIn('id', $ids)->orderBy('code')->get() as $a) {
            $o = isset($perAccount[$a->id]) ? $net($perAccount[$a->id]) : 0;
            $p = isset($perAccountPeriod[$a->id]) ? $net($perAccountPeriod[$a->id]) : 0;
            $accounts[] = ['name' => $a->name, 'opening' => $o, 'change' => $p, 'closing' => $o + $p];
            $opening += $o;
        }

        return ['opening' => $opening, 'closing' => $opening + $in - $out, 'rows' => $rows, 'in' => $in, 'out' => $out, 'accounts' => $accounts];
    }
}
