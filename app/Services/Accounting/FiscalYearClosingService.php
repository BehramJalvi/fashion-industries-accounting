<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\FiscalYearCloseEvent;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Support\AccountCodes;
use App\Support\Money;
use DomainException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class FiscalYearClosingService
{
    public function __construct(private PostingService $posting) {}

    public function close(FiscalYear $fiscalYear, ?string $reason = null): FiscalYearCloseEvent
    {
        return DB::transaction(function () use ($fiscalYear, $reason): FiscalYearCloseEvent {
            $year = FiscalYear::query()->lockForUpdate()->findOrFail($fiscalYear->id);
            $latestCycle = (int) FiscalYearCloseEvent::query()
                ->where('fiscal_year_id', $year->id)
                ->max('cycle');
            $latestClose = FiscalYearCloseEvent::query()
                ->where('fiscal_year_id', $year->id)
                ->where('cycle', $latestCycle)
                ->where('event_type', 'close')
                ->first();
            $latestReopen = FiscalYearCloseEvent::query()
                ->where('fiscal_year_id', $year->id)
                ->where('cycle', $latestCycle)
                ->where('event_type', 'reopen')
                ->exists();

            if ($year->is_closed && $latestClose && ! $latestReopen) {
                return $latestClose;
            }
            if ($year->is_closed || ($latestClose && ! $latestReopen)) {
                throw new DomainException('Fiscal-year status does not match its close history.');
            }

            $this->validateFiscalYear($year);
            $lines = $this->closingLines($year);
            $journalEntry = null;
            if ($lines !== []) {
                $journalEntry = $this->posting->post([
                    'date' => $year->end_date->toDateString(),
                    'voucher_type' => 'year_end_close',
                    'reference' => "FY-{$year->id}-CLOSE-".($latestCycle + 1),
                    'description' => "Fiscal year close - {$year->name}",
                    'source' => $year,
                    'lines' => $lines,
                ]);
            }

            $year->forceFill(['is_closed' => true])->save();

            return FiscalYearCloseEvent::create([
                'fiscal_year_id' => $year->id,
                'cycle' => $latestCycle + 1,
                'event_type' => 'close',
                'journal_entry_id' => $journalEntry?->id,
                'reason' => $reason,
                'created_by' => Auth::id(),
            ]);
        });
    }

    public function reopen(FiscalYear $fiscalYear, string $reason): FiscalYearCloseEvent
    {
        return DB::transaction(function () use ($fiscalYear, $reason): FiscalYearCloseEvent {
            $year = FiscalYear::query()->lockForUpdate()->findOrFail($fiscalYear->id);
            $cycle = (int) FiscalYearCloseEvent::query()
                ->where('fiscal_year_id', $year->id)
                ->max('cycle');
            $closeEvent = FiscalYearCloseEvent::query()
                ->where('fiscal_year_id', $year->id)
                ->where('cycle', $cycle)
                ->where('event_type', 'close')
                ->first();
            $alreadyReopened = FiscalYearCloseEvent::query()
                ->where('fiscal_year_id', $year->id)
                ->where('cycle', $cycle)
                ->where('event_type', 'reopen')
                ->exists();

            if (! $year->is_closed || ! $closeEvent || $alreadyReopened) {
                throw new DomainException('Only the currently closed fiscal year can be reopened.');
            }

            $year->forceFill(['is_closed' => false])->save();
            $reversal = $closeEvent->journal_entry_id
                ? $this->posting->reverse(
                    JournalEntry::query()->findOrFail($closeEvent->journal_entry_id),
                    "Fiscal year reopened: {$reason}",
                    $year->end_date->toDateString()
                )
                : null;

            return FiscalYearCloseEvent::create([
                'fiscal_year_id' => $year->id,
                'cycle' => $cycle,
                'event_type' => 'reopen',
                'journal_entry_id' => $closeEvent->journal_entry_id,
                'reversal_entry_id' => $reversal?->id,
                'reason' => $reason,
                'created_by' => Auth::id(),
            ]);
        });
    }

    private function validateFiscalYear(FiscalYear $year): void
    {
        if ($year->start_date->gt($year->end_date)) {
            throw new DomainException('The fiscal-year start date must be on or before its end date.');
        }

        $overlapsAnotherYear = FiscalYear::query()
            ->where('id', '!=', $year->id)
            ->whereDate('start_date', '<=', $year->end_date->toDateString())
            ->whereDate('end_date', '>=', $year->start_date->toDateString())
            ->exists();
        if ($overlapsAnotherYear) {
            throw new DomainException('Overlapping fiscal years must be corrected before closing.');
        }

        $outOfRangeEntry = JournalEntry::query()
            ->where('fiscal_year_id', $year->id)
            ->where(fn ($query) => $query
                ->whereDate('entry_date', '<', $year->start_date->toDateString())
                ->orWhereDate('entry_date', '>', $year->end_date->toDateString()))
            ->exists();
        if ($outOfRangeEntry) {
            throw new DomainException('Journal entries assigned to this fiscal year must fall within its date range.');
        }

        $balances = JournalEntryLine::query()
            ->join('journal_entries as entries', 'entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->where('entries.fiscal_year_id', $year->id)
            ->selectRaw('entries.id, SUM(journal_entry_lines.debit) as debits, SUM(journal_entry_lines.credit) as credits')
            ->groupBy('entries.id')
            ->get();

        foreach ($balances as $balance) {
            if (Money::toCents($balance->debits) !== Money::toCents($balance->credits)) {
                throw new DomainException("Journal entry {$balance->id} is unbalanced; correct it before closing.");
            }
        }
    }

    /**
     * @return array<int, array{account: int, debit?: string, credit?: string}>
     */
    private function closingLines(FiscalYear $year): array
    {
        $balances = JournalEntryLine::query()
            ->join('journal_entries as entries', 'entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->join('accounts', 'accounts.id', '=', 'journal_entry_lines.account_id')
            ->where('entries.fiscal_year_id', $year->id)
            ->whereDate('entries.entry_date', '>=', $year->start_date->toDateString())
            ->whereDate('entries.entry_date', '<=', $year->end_date->toDateString())
            ->whereNotIn('accounts.type', ['asset', 'liability', 'equity'])
            ->selectRaw('journal_entry_lines.account_id, SUM(journal_entry_lines.debit) as debits, SUM(journal_entry_lines.credit) as credits')
            ->groupBy('journal_entry_lines.account_id')
            ->get();

        $lines = [];
        $profitCents = 0;
        foreach ($balances as $balance) {
            $netDebit = Money::toCents($balance->debits) - Money::toCents($balance->credits);
            if ($netDebit === 0) {
                continue;
            }

            $profitCents -= $netDebit;
            $lines[] = [
                'account' => (int) $balance->account_id,
                ...($netDebit > 0
                    ? ['credit' => Money::fromCents($netDebit)]
                    : ['debit' => Money::fromCents(abs($netDebit))]),
            ];
        }

        if ($profitCents !== 0) {
            $retainedEarnings = Account::query()->where('code', AccountCodes::RETAINED_EARNINGS)->first();
            if (! $retainedEarnings?->is_active) {
                throw new DomainException('The active Retained Earnings account is required to close a non-zero profit or loss.');
            }

            $lines[] = [
                'account' => $retainedEarnings->id,
                ...($profitCents > 0
                    ? ['credit' => Money::fromCents($profitCents)]
                    : ['debit' => Money::fromCents(abs($profitCents))]),
            ];
        }

        return $lines;
    }
}
