<?php

namespace App\Models\Concerns;

use App\Models\JournalEntryLine;

/**
 * Party balances are never stored. They are derived from journal_entry_lines
 * (control accounts + this party). Cancelled documents net to zero through their
 * reversing entries, so no status filter is needed.
 */
trait HasLedgerBalance
{
    /** Account codes whose lines make up this party's balance. */
    abstract protected function controlAccountCodes(): array;

    /** 'debit' => positive balance means they owe us; 'credit' => we owe them. */
    abstract protected function balanceNature(): string;

    /** Public view of the control accounts / nature, for services that build statements and bulk balances. */
    public function ledgerControlCodes(): array
    {
        return $this->controlAccountCodes();
    }

    public function ledgerNature(): string
    {
        return $this->balanceNature();
    }

    public function ledgerBalance(?string $asOf = null): string
    {
        $sign = $this->balanceNature() === 'debit' ? '1' : '-1';

        $query = JournalEntryLine::query()
            ->join('journal_entries as e', 'e.id', '=', 'journal_entry_lines.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'journal_entry_lines.account_id')
            ->where('journal_entry_lines.party_type', $this->getMorphClass())
            ->where('journal_entry_lines.party_id', $this->getKey())
            ->whereIn('a.code', $this->controlAccountCodes());

        if ($asOf) {
            $query->where('e.entry_date', '<=', $asOf);
        }

        $row = $query->selectRaw("{$sign} * COALESCE(SUM(journal_entry_lines.debit) - SUM(journal_entry_lines.credit), 0) as bal")->first();

        return number_format((float) ($row->bal ?? 0), 2, '.', '');
    }
}
