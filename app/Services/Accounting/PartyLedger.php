<?php

namespace App\Services\Accounting;

use App\Models\JournalEntryLine;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;

/**
 * Party balances and statements, always derived from journal_entry_lines (no stored balances anywhere).
 * Amounts are integer paisa. Sign convention: a POSITIVE balance is the party's natural side
 * (customer owes us / we owe supplier / we owe employee / owner's net capital).
 */
class PartyLedger
{
    /** Balance of every party of one type in a single query. @return array<int, int> party_id => paisa */
    public function balances(string $type, array $controlCodes, string $nature, ?string $asOf = null): array
    {
        $rows = JournalEntryLine::query()
            ->join('accounts as a', 'a.id', '=', 'journal_entry_lines.account_id')
            ->where('journal_entry_lines.party_type', $type)
            ->whereIn('a.code', $controlCodes)
            ->when($asOf, fn ($q) => $q->join('journal_entries as e', 'e.id', '=', 'journal_entry_lines.journal_entry_id')->where('e.entry_date', '<=', $asOf))
            ->selectRaw('journal_entry_lines.party_id as pid, SUM(journal_entry_lines.debit) as d, SUM(journal_entry_lines.credit) as c')
            ->groupBy('journal_entry_lines.party_id')->get();

        $out = [];
        foreach ($rows as $r) {
            $net = Money::toCents($r->d) - Money::toCents($r->c);
            $out[(int) $r->pid] = $nature === 'debit' ? $net : -$net;
        }

        return $out;
    }

    /**
     * Statement for one party. Cancelled documents appear with their reversal so the running balance is the truth.
     *
     * @return array{opening:int, rows:array<int,array>, debit:int, credit:int, closing:int}
     */
    public function statement(Model $party, ?string $from = null, ?string $to = null): array
    {
        $sign = $party->ledgerNature() === 'debit' ? 1 : -1;

        $base = fn () => JournalEntryLine::query()
            ->join('journal_entries as e', 'e.id', '=', 'journal_entry_lines.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'journal_entry_lines.account_id')
            ->where('journal_entry_lines.party_type', $party->getMorphClass())
            ->where('journal_entry_lines.party_id', $party->getKey())
            ->whereIn('a.code', $party->ledgerControlCodes());

        $opening = 0;
        if ($from) {
            $o = $base()->where('e.entry_date', '<', $from)
                ->selectRaw('SUM(journal_entry_lines.debit) as d, SUM(journal_entry_lines.credit) as c')->first();
            $opening = $sign * (Money::toCents($o->d) - Money::toCents($o->c));
        }

        $lines = $base()
            ->when($from, fn ($q, $v) => $q->where('e.entry_date', '>=', $v))
            ->when($to, fn ($q, $v) => $q->where('e.entry_date', '<=', $v))
            ->orderBy('e.entry_date')->orderBy('e.id')->orderBy('journal_entry_lines.line_no')
            ->get(['e.id as entry_id', 'e.entry_no', 'e.entry_date', 'e.voucher_type', 'e.reference', 'e.description', 'e.status',
                   'a.code as account_code', 'journal_entry_lines.debit', 'journal_entry_lines.credit', 'journal_entry_lines.narration']);

        $running = $opening;
        $debitTotal = $creditTotal = 0;
        $rows = [];
        foreach ($lines as $l) {
            $d = Money::toCents($l->debit);
            $c = Money::toCents($l->credit);
            $running += $sign * ($d - $c);
            $debitTotal += $d;
            $creditTotal += $c;
            $rows[] = [
                'entry_id' => $l->entry_id, 'entry_no' => $l->entry_no, 'date' => substr((string) $l->entry_date, 0, 10),
                'voucher_type' => $l->voucher_type, 'reference' => $l->reference, 'description' => $l->narration ?: $l->description,
                'status' => $l->status, 'debit' => $d, 'credit' => $c, 'balance' => $running,
            ];
        }

        return ['opening' => $opening, 'rows' => $rows, 'debit' => $debitTotal, 'credit' => $creditTotal, 'closing' => $running];
    }
}
