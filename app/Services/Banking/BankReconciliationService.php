<?php

namespace App\Services\Banking;

use App\Models\Account;
use App\Models\BankReconciliation;
use App\Models\BankReconciliationLine;
use App\Models\JournalEntryLine;
use App\Support\AuditLogger;
use App\Support\Money;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class BankReconciliationService
{
    private const MAX_STATEMENT_LINES = 5000;

    /**
     * @return list<array{statement_date: string, description: string, reference: ?string, signed_amount: string}>
     */
    public function parseCsv(UploadedFile $file, string $periodStart, string $periodEnd): array
    {
        $handle = fopen($file->getRealPath(), 'rb');
        if ($handle === false) {
            throw ValidationException::withMessages(['statement_file' => 'The statement file could not be read.']);
        }

        try {
            $headers = fgetcsv($handle, null, ',', '"', '');
            if (! is_array($headers)) {
                throw ValidationException::withMessages(['statement_file' => 'The CSV file is empty.']);
            }

            $headers = array_map(
                fn ($header) => strtolower(trim(ltrim((string) $header, "\xEF\xBB\xBF"))),
                $headers
            );
            $requiredHeaders = ['date', 'description', 'reference', 'amount'];
            if (count($headers) !== count($requiredHeaders) || array_diff($requiredHeaders, $headers) !== []) {
                throw ValidationException::withMessages([
                    'statement_file' => 'CSV headers must be date, description, reference, amount (in that order or any order).',
                ]);
            }
            $positions = array_flip($headers);
            $lines = [];
            $rowNumber = 1;

            while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
                $rowNumber++;
                if (count(array_filter($row, fn ($value) => $value !== null && trim((string) $value) !== '')) === 0) {
                    continue;
                }
                if (count($row) !== count($headers)) {
                    throw ValidationException::withMessages(['statement_file' => "CSV row {$rowNumber} must contain exactly four columns."]);
                }

                $amount = trim((string) ($row[$positions['amount']] ?? ''));
                $data = [
                    'statement_date' => trim((string) ($row[$positions['date']] ?? '')),
                    'description' => trim((string) ($row[$positions['description']] ?? '')),
                    'reference' => trim((string) ($row[$positions['reference']] ?? '')) ?: null,
                    'amount' => $amount,
                ];
                $validator = Validator::make($data, [
                    'statement_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$periodStart, 'before_or_equal:'.$periodEnd],
                    'description' => ['required', 'string', 'max:255'],
                    'reference' => ['nullable', 'string', 'max:60'],
                    'amount' => ['required', 'numeric', 'decimal:0,2', 'between:-9999999999999,9999999999999', 'regex:/^-?\d+(?:\.\d{1,2})?$/'],
                ]);

                if ($validator->fails()) {
                    throw ValidationException::withMessages([
                        'statement_file' => "CSV row {$rowNumber}: ".$validator->errors()->first(),
                    ]);
                }

                $amountCents = Money::toCents($amount);
                if ($amountCents === 0) {
                    throw ValidationException::withMessages(['statement_file' => "CSV row {$rowNumber}: amount must be non-zero."]);
                }

                $lines[] = [
                    'statement_date' => $data['statement_date'],
                    'description' => $data['description'],
                    'reference' => $data['reference'],
                    'signed_amount' => Money::fromCents($amountCents),
                ];
                if (count($lines) > self::MAX_STATEMENT_LINES) {
                    throw ValidationException::withMessages([
                        'statement_file' => 'A statement may contain no more than 5,000 lines.',
                    ]);
                }
            }
        } finally {
            fclose($handle);
        }

        if ($lines === []) {
            throw ValidationException::withMessages(['statement_file' => 'The CSV file contains no statement lines.']);
        }

        return $lines;
    }

    /**
     * @param  array{account_id: int|string, period_start: string, period_end: string, opening_balance: string|int|float, closing_balance: string|int|float}  $header
     * @param  list<array{statement_date: string, description: string, reference: ?string, signed_amount: string}>  $lines
     */
    public function createStatement(array $header, array $lines, string $source): BankReconciliation
    {
        return DB::transaction(function () use ($header, $lines, $source) {
            $account = Account::query()->whereKey($header['account_id'])->where('sub_type', 'bank')->where('is_active', true)->lockForUpdate()->first();
            if (! $account) {
                throw new DomainException('Choose an active bank account.');
            }

            $start = CarbonImmutable::parse($header['period_start'])->startOfDay();
            $end = CarbonImmutable::parse($header['period_end'])->startOfDay();
            if ($end->lt($start)) {
                throw new DomainException('The statement end date must not be before the start date.');
            }
            if (BankReconciliation::query()
                ->where('account_id', $account->id)
                ->whereDate('period_start', '<=', $end)
                ->whereDate('period_end', '>=', $start)
                ->exists()) {
                throw new DomainException('A reconciliation already exists for this bank account and overlaps the selected statement period.');
            }

            $reconciliation = BankReconciliation::create([
                'account_id' => $account->id,
                'period_start' => $start->toDateString(),
                'period_end' => $end->toDateString(),
                'opening_balance' => Money::fromCents(Money::toCents($header['opening_balance'])),
                'closing_balance' => Money::fromCents(Money::toCents($header['closing_balance'])),
                'source' => $source,
                'status' => 'draft',
                'created_by' => Auth::id(),
            ]);

            foreach ($lines as $line) {
                $reconciliation->lines()->create([
                    ...$line,
                    'created_by' => Auth::id(),
                ]);
            }

            AuditLogger::log('bank_reconciliation.statement_created', $reconciliation, null, [
                'account_id' => $account->id,
                'period_start' => $start->toDateString(),
                'period_end' => $end->toDateString(),
                'source' => $source,
                'line_count' => count($lines),
            ]);

            $this->autoMatchLocked($reconciliation);

            return $reconciliation->refresh();
        });
    }

    /** @param array{statement_date: string, description: string, reference?: ?string, signed_amount: string|int|float} $data */
    public function addManualLine(BankReconciliation $reconciliation, array $data): BankReconciliationLine
    {
        return DB::transaction(function () use ($reconciliation, $data) {
            $record = BankReconciliation::query()->lockForUpdate()->findOrFail($reconciliation->id);
            $this->assertDraft($record);
            $date = CarbonImmutable::parse($data['statement_date'])->startOfDay();

            if ($date->lt($record->period_start) || $date->gt($record->period_end)) {
                throw new DomainException('The statement line date must be within the statement period.');
            }
            $amountCents = Money::toCents($data['signed_amount']);
            if ($amountCents === 0) {
                throw new DomainException('The statement amount must be non-zero.');
            }

            $line = $record->lines()->create([
                'statement_date' => $date->toDateString(),
                'description' => $data['description'],
                'reference' => $data['reference'] ?? null,
                'signed_amount' => Money::fromCents($amountCents),
                'created_by' => Auth::id(),
            ]);
            AuditLogger::log('bank_reconciliation.statement_line_added', $line, null, [
                'reconciliation_id' => $record->id,
                'statement_date' => $date->toDateString(),
                'signed_amount' => $line->signed_amount,
            ]);
            $this->autoMatchLine($record, $line);

            return $line->refresh();
        });
    }

    /** @param array{statement_date: string, description: string, reference?: ?string, signed_amount: string|int|float} $data */
    public function updateLine(BankReconciliation $reconciliation, BankReconciliationLine $line, array $data): BankReconciliationLine
    {
        return DB::transaction(function () use ($reconciliation, $line, $data) {
            $record = BankReconciliation::query()->lockForUpdate()->findOrFail($reconciliation->id);
            $this->assertDraft($record);
            $statementLine = BankReconciliationLine::query()
                ->where('reconciliation_id', $record->id)
                ->lockForUpdate()
                ->findOrFail($line->id);
            $date = CarbonImmutable::parse($data['statement_date'])->startOfDay();
            if ($date->lt($record->period_start) || $date->gt($record->period_end)) {
                throw new DomainException('The statement line date must be within the statement period.');
            }
            $amountCents = Money::toCents($data['signed_amount']);
            if ($amountCents === 0) {
                throw new DomainException('The statement amount must be non-zero.');
            }

            $before = $statementLine->only(['statement_date', 'description', 'reference', 'signed_amount', 'matched_journal_entry_line_id']);
            $statementLine->forceFill([
                'statement_date' => $date->toDateString(),
                'description' => $data['description'],
                'reference' => $data['reference'] ?? null,
                'signed_amount' => Money::fromCents($amountCents),
                'matched_journal_entry_line_id' => null,
                'matched_by' => null,
                'matched_at' => null,
            ])->save();
            AuditLogger::log('bank_reconciliation.statement_line_updated', $statementLine, $before, [
                ...$statementLine->only(['statement_date', 'description', 'reference', 'signed_amount']),
                'reconciliation_id' => $record->id,
            ]);
            $this->autoMatchLine($record, $statementLine);

            return $statementLine->refresh();
        });
    }

    public function deleteLine(BankReconciliation $reconciliation, BankReconciliationLine $line): void
    {
        DB::transaction(function () use ($reconciliation, $line) {
            $record = BankReconciliation::query()->lockForUpdate()->findOrFail($reconciliation->id);
            $this->assertDraft($record);
            $statementLine = BankReconciliationLine::query()
                ->where('reconciliation_id', $record->id)
                ->lockForUpdate()
                ->findOrFail($line->id);
            $before = $statementLine->only(['statement_date', 'description', 'reference', 'signed_amount', 'matched_journal_entry_line_id']);
            AuditLogger::log('bank_reconciliation.statement_line_deleted', $statementLine, $before, [
                'reconciliation_id' => $record->id,
            ]);
            $statementLine->delete();
        });
    }

    public function autoMatch(BankReconciliation $reconciliation): int
    {
        return DB::transaction(function () use ($reconciliation) {
            $record = BankReconciliation::query()->lockForUpdate()->findOrFail($reconciliation->id);
            $this->assertDraft($record);

            return $this->autoMatchLocked($record);
        });
    }

    public function matchLine(BankReconciliation $reconciliation, BankReconciliationLine $line, int $journalEntryLineId): void
    {
        DB::transaction(function () use ($reconciliation, $line, $journalEntryLineId) {
            $record = BankReconciliation::query()->lockForUpdate()->findOrFail($reconciliation->id);
            $this->assertDraft($record);
            $statementLine = BankReconciliationLine::query()
                ->where('reconciliation_id', $record->id)
                ->lockForUpdate()
                ->findOrFail($line->id);
            if ($statementLine->matched_journal_entry_line_id) {
                throw new DomainException('This statement line is already matched.');
            }

            $journalLine = JournalEntryLine::query()->with('entry')->lockForUpdate()->findOrFail($journalEntryLineId);
            $this->assertEligibleMatch($record, $statementLine, $journalLine);
            if (BankReconciliationLine::query()->where('matched_journal_entry_line_id', $journalLine->id)->exists()) {
                throw new DomainException('That posted bank transaction is already matched to a statement line.');
            }

            $statementLine->forceFill([
                'matched_journal_entry_line_id' => $journalLine->id,
                'matched_by' => Auth::id(),
                'matched_at' => now(),
            ])->save();
            AuditLogger::log('bank_reconciliation.line_matched', $statementLine, null, [
                'journal_entry_line_id' => $journalLine->id,
                'journal_entry_id' => $journalLine->journal_entry_id,
            ]);
        });
    }

    public function reconcile(BankReconciliation $reconciliation): BankReconciliation
    {
        return DB::transaction(function () use ($reconciliation) {
            $record = BankReconciliation::query()->lockForUpdate()->findOrFail($reconciliation->id);
            $this->assertDraft($record);
            $summary = $this->summary($record);

            if ($summary['line_count'] === 0) {
                throw new DomainException('Add statement lines before reconciling.');
            }
            if ($summary['difference_cents'] !== 0) {
                throw new DomainException('The closing balance does not equal the opening balance plus the statement lines.');
            }
            if ($summary['unmatched_statement_count'] > 0) {
                throw new DomainException('Match every statement line before reconciling.');
            }

            $record->forceFill([
                'status' => 'reconciled',
                'reconciled_by' => Auth::id(),
                'reconciled_at' => now(),
                'summary' => $summary,
            ])->save();
            AuditLogger::log('bank_reconciliation.reconciled', $record, ['status' => 'draft'], [
                'status' => 'reconciled',
                'account_id' => $record->account_id,
                'period_start' => $record->period_start->toDateString(),
                'period_end' => $record->period_end->toDateString(),
            ]);

            return $record;
        });
    }

    /**
     * @return array{line_count:int, matched_statement_count:int, unmatched_statement_count:int, statement_total_cents:int, expected_close_cents:int, difference_cents:int, unmatched_book_count:int, unmatched_book_cents:int}
     */
    public function summary(BankReconciliation $reconciliation): array
    {
        if ($reconciliation->status === 'reconciled' && $reconciliation->summary) {
            return $reconciliation->summary;
        }

        $lines = $reconciliation->lines()->get(['signed_amount', 'matched_journal_entry_line_id']);
        $statementTotal = $lines->sum(fn (BankReconciliationLine $line) => Money::toCents($line->signed_amount));
        $matchedStatementCount = $lines->whereNotNull('matched_journal_entry_line_id')->count();
        $opening = Money::toCents($reconciliation->opening_balance);
        $closing = Money::toCents($reconciliation->closing_balance);

        $unmatchedBookQuery = JournalEntryLine::query()
            ->where('account_id', $reconciliation->account_id)
            ->whereHas('entry', fn ($query) => $query->where('status', 'posted')
                ->whereDate('entry_date', '>=', $reconciliation->period_start)
                ->whereDate('entry_date', '<=', $reconciliation->period_end))
            ->whereDoesntHave('reconciliationMatches');
        $unmatchedBookCents = Money::toCents((string) ($unmatchedBookQuery->sum(DB::raw('debit - credit')) ?? 0));

        return [
            'line_count' => $lines->count(),
            'matched_statement_count' => $matchedStatementCount,
            'unmatched_statement_count' => $lines->count() - $matchedStatementCount,
            'statement_total_cents' => $statementTotal,
            'expected_close_cents' => $opening + $statementTotal,
            'difference_cents' => $closing - ($opening + $statementTotal),
            'unmatched_book_count' => $unmatchedBookQuery->count(),
            'unmatched_book_cents' => $unmatchedBookCents,
        ];
    }

    /**
     * @return Collection<int, JournalEntryLine>
     */
    public function unmatchedBookLines(BankReconciliation $reconciliation)
    {
        return JournalEntryLine::query()
            ->with('entry:id,entry_no,entry_date,reference,description')
            ->where('account_id', $reconciliation->account_id)
            ->whereHas('entry', fn ($query) => $query->where('status', 'posted')
                ->whereDate('entry_date', '>=', $reconciliation->period_start)
                ->whereDate('entry_date', '<=', $reconciliation->period_end))
            ->whereDoesntHave('reconciliationMatches')
            ->orderBy('journal_entry_id')
            ->orderBy('line_no')
            ->limit(100)
            ->get();
    }

    /**
     * @return Collection<int, JournalEntryLine>
     */
    public function candidatesForLine(BankReconciliation $reconciliation, BankReconciliationLine $line)
    {
        $cents = Money::toCents($line->signed_amount);

        return JournalEntryLine::query()
            ->with('entry:id,entry_no,entry_date,reference,description')
            ->where('account_id', $reconciliation->account_id)
            ->where('debit', $cents > 0 ? Money::fromCents($cents) : '0.00')
            ->where('credit', $cents < 0 ? Money::fromCents(abs($cents)) : '0.00')
            ->whereHas('entry', fn ($query) => $query->where('status', 'posted')
                ->whereDate('entry_date', '>=', $reconciliation->period_start)
                ->whereDate('entry_date', '<=', $reconciliation->period_end))
            ->whereDoesntHave('reconciliationMatches')
            ->orderBy('journal_entry_id')
            ->orderBy('line_no')
            ->limit(100)
            ->get();
    }

    private function autoMatchLocked(BankReconciliation $reconciliation): int
    {
        $matched = 0;
        $lines = $reconciliation->lines()->whereNull('matched_journal_entry_line_id')->lockForUpdate()->get();

        foreach ($lines as $line) {
            $candidates = $this->candidatesForLine($reconciliation, $line)
                ->filter(function (JournalEntryLine $candidate) use ($line) {
                    if ($candidate->entry->entry_date->toDateString() !== $line->statement_date->toDateString()) {
                        return false;
                    }

                    return ! $line->reference || mb_strtolower(trim((string) $candidate->entry->reference)) === mb_strtolower(trim($line->reference));
                })
                ->values();

            if ($candidates->count() === 1) {
                $candidate = JournalEntryLine::query()
                    ->with('entry')
                    ->lockForUpdate()
                    ->find($candidates->first()->id);
                if (! $candidate || BankReconciliationLine::query()->where('matched_journal_entry_line_id', $candidate->id)->exists()) {
                    continue;
                }
                $this->assertEligibleMatch($reconciliation, $line, $candidate);
                $line->forceFill([
                    'matched_journal_entry_line_id' => $candidate->id,
                    'matched_by' => Auth::id(),
                    'matched_at' => now(),
                ])->save();
                AuditLogger::log('bank_reconciliation.line_auto_matched', $line, null, [
                    'journal_entry_line_id' => $candidate->id,
                    'journal_entry_id' => $candidate->journal_entry_id,
                ]);
                $matched++;
            }
        }

        return $matched;
    }

    private function autoMatchLine(BankReconciliation $reconciliation, BankReconciliationLine $line): void
    {
        $matches = $this->candidatesForLine($reconciliation, $line)
            ->filter(fn (JournalEntryLine $candidate) => $candidate->entry->entry_date->toDateString() === $line->statement_date->toDateString()
                && (! $line->reference || mb_strtolower(trim((string) $candidate->entry->reference)) === mb_strtolower(trim($line->reference))))
            ->values();

        if ($matches->count() === 1) {
            $candidate = JournalEntryLine::query()
                ->with('entry')
                ->lockForUpdate()
                ->find($matches->first()->id);
            if (! $candidate || BankReconciliationLine::query()->where('matched_journal_entry_line_id', $candidate->id)->exists()) {
                return;
            }
            $this->assertEligibleMatch($reconciliation, $line, $candidate);
            $line->forceFill([
                'matched_journal_entry_line_id' => $candidate->id,
                'matched_by' => Auth::id(),
                'matched_at' => now(),
            ])->save();
            AuditLogger::log('bank_reconciliation.line_auto_matched', $line, null, [
                'journal_entry_line_id' => $candidate->id,
                'journal_entry_id' => $candidate->journal_entry_id,
            ]);
        }
    }

    private function assertEligibleMatch(
        BankReconciliation $reconciliation,
        BankReconciliationLine $statementLine,
        JournalEntryLine $journalLine
    ): void {
        $entry = $journalLine->entry;
        if ($journalLine->account_id !== $reconciliation->account_id || ! $entry || $entry->status !== 'posted') {
            throw new DomainException('Choose a posted transaction from this bank account.');
        }

        if ($entry->entry_date->lt($reconciliation->period_start) || $entry->entry_date->gt($reconciliation->period_end)) {
            throw new DomainException('The posted transaction date is outside the statement period.');
        }

        $journalCents = Money::toCents($journalLine->debit) - Money::toCents($journalLine->credit);
        if ($journalCents !== Money::toCents($statementLine->signed_amount)) {
            throw new DomainException('The posted transaction amount does not match the statement line.');
        }
    }

    private function assertDraft(BankReconciliation $reconciliation): void
    {
        if ($reconciliation->status !== 'draft') {
            throw new DomainException('A reconciled statement is immutable.');
        }
    }
}
