<?php

namespace App\Services\Accounting;

use App\Exceptions\Accounting\PostingException;
use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\NumberSequence;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The ONLY place journal entries are created. Every document service (sales, purchases, receipts ...) calls this.
 *
 * Guarantees: balanced (exact, in paisa), one side per line, active accounts, control accounts carry the right party,
 * date inside an open fiscal year, all-or-nothing (DB transaction; joins the caller's transaction if one is open).
 *
 * Cancelling never deletes: reverse() adds a mirror entry and flags the original 'cancelled'. Reports must therefore
 * sum ALL lines regardless of entry status; an original and its reversal net to zero.
 */
class PostingService
{
    /**
     * @param array{
     *   date: string|\DateTimeInterface,
     *   voucher_type: string,
     *   description?: ?string,
     *   reference?: ?string,
     *   source?: ?Model,
     *   lines: array<int, array{account: Account|int|string, debit?: string|int|float|null, credit?: string|int|float|null,
     *                           party?: ?Model, product_id?: ?int, narration?: ?string}>
     * } $entry  `account` accepts an Account, an id, or an account code such as '1100'.
     */
    public function post(array $entry): JournalEntry
    {
        $date = Carbon::parse($entry['date'])->startOfDay();
        [$lines, $totalCents] = $this->normaliseLines($entry['lines'] ?? []);

        return DB::transaction(function () use ($entry, $date, $lines, $totalCents) {
            $fiscalYear = $this->openFiscalYear($date);
            $source = $entry['source'] ?? null;

            $header = new JournalEntry([
                'entry_no' => NumberSequence::next('journal_entry'),
                'entry_date' => $date->toDateString(),
                'fiscal_year_id' => $fiscalYear->id,
                'voucher_type' => $entry['voucher_type'],
                'reference' => $entry['reference'] ?? null,
                'description' => $entry['description'] ?? null,
                'source_type' => $source?->getMorphClass(),
                'source_id' => $source?->getKey(),
                'reverses_entry_id' => $entry['reverses_entry_id'] ?? null,
                'total_amount' => Money::fromCents($totalCents),
            ]);
            $header->forceFill(['status' => 'posted', 'created_by' => Auth::id(), 'posted_at' => now()])->save();

            foreach ($lines as $i => $line) {
                JournalEntryLine::create([
                    'journal_entry_id' => $header->id,
                    'line_no' => $i + 1,
                    'account_id' => $line['account']->id,
                    'debit' => Money::fromCents($line['debit']),
                    'credit' => Money::fromCents($line['credit']),
                    'party_type' => $line['party']?->getMorphClass(),
                    'party_id' => $line['party']?->getKey(),
                    'product_id' => $line['product_id'],
                    'narration' => $line['narration'],
                ]);
            }

            return $header->load('lines');
        });
    }

    /**
     * Cancel an entry by posting its mirror image. Default date = the original's date, so the period shows no trace
     * of the cancelled transaction; if that fiscal year is closed, today's date is used instead.
     */
    public function reverse(JournalEntry $entry, ?string $reason = null, string|\DateTimeInterface|null $date = null): JournalEntry
    {
        return DB::transaction(function () use ($entry, $reason, $date) {
            $original = JournalEntry::with('lines')->lockForUpdate()->findOrFail($entry->getKey());

            if ($original->status === 'cancelled') {
                throw PostingException::alreadyCancelled($original->entry_no);
            }
            if ($original->reverses_entry_id !== null) {
                throw PostingException::cannotReverseReversal($original->entry_no);
            }

            $date ??= $original->entry_date;
            if (FiscalYear::forDate(Carbon::parse($date)->toDateString())?->is_closed) {
                $date = now();
            }

            $lines = $original->lines->map(fn (JournalEntryLine $l) => [
                'account' => $l->account_id,
                'debit' => $l->credit,          // swapped
                'credit' => $l->debit,
                'party' => $this->partyOf($l),
                'product_id' => $l->product_id,
                'narration' => 'Reversal of '.$original->entry_no,
            ])->all();

            $reversal = $this->post([
                'date' => $date,
                'voucher_type' => 'reversal',
                'reference' => $original->reference,
                'description' => trim("Reversal of {$original->entry_no}".($reason ? ": {$reason}" : '')),
                'reverses_entry_id' => $original->id,
                'lines' => $lines,
            ]);

            $original->forceFill(['status' => 'cancelled'])->save();   // the only change JournalEntry allows

            return $reversal;
        });
    }

    // ------------------------------------------------------------------ internals

    private function openFiscalYear(Carbon $date): FiscalYear
    {
        $year = FiscalYear::query()
            ->whereDate('start_date', '<=', $date->toDateString())
            ->whereDate('end_date', '>=', $date->toDateString())
            ->lockForUpdate()
            ->first();

        if (! $year || $year->is_closed) {
            throw PostingException::noOpenFiscalYear($date->toDateString());
        }

        return $year;
    }

    /** @return array{0: array<int, array>, 1: int} normalised lines and the entry total in paisa */
    private function normaliseLines(array $rawLines): array
    {
        if (count($rawLines) < 2) {
            throw PostingException::tooFewLines();
        }

        $lines = [];
        $debitTotal = $creditTotal = 0;

        foreach (array_values($rawLines) as $i => $raw) {
            $n = $i + 1;
            $account = $this->resolveAccount($raw['account'] ?? null, $n);
            $debit = Money::toCents($raw['debit'] ?? 0);
            $credit = Money::toCents($raw['credit'] ?? 0);

            if ($debit < 0 || $credit < 0) {
                throw PostingException::invalidLine($n, 'amounts cannot be negative.');
            }
            if (($debit > 0) === ($credit > 0)) {
                throw PostingException::invalidLine($n, 'enter either a debit or a credit amount (not both, not neither).');
            }

            $party = $raw['party'] ?? null;
            if ($account->party_type) {
                if (! $party || ! $party->exists) {
                    throw PostingException::invalidLine($n, "{$account->name} requires a {$account->party_type}.");
                }
                if ($party->getMorphClass() !== $account->party_type) {
                    throw PostingException::invalidLine($n, "{$account->name} requires a {$account->party_type}, not a {$party->getMorphClass()}.");
                }
            } elseif ($party) {
                throw PostingException::invalidLine($n, "{$account->name} does not take a customer, supplier, employee or owner.");
            }

            $debitTotal += $debit;
            $creditTotal += $credit;
            $lines[] = [
                'account' => $account, 'debit' => $debit, 'credit' => $credit, 'party' => $party,
                'product_id' => $raw['product_id'] ?? null, 'narration' => $raw['narration'] ?? null,
            ];
        }

        if ($debitTotal !== $creditTotal) {
            throw PostingException::unbalanced($debitTotal, $creditTotal);
        }
        if ($debitTotal === 0) {
            throw PostingException::zeroAmount();
        }

        return [$lines, $debitTotal];
    }

    private function resolveAccount(Account|int|string|null $ref, int $lineNo): Account
    {
        $account = match (true) {
            $ref instanceof Account => $ref,
            is_int($ref) => Account::find($ref),
            is_string($ref) => Account::where('code', $ref)->first(),
            default => null,
        };

        if (! $account) {
            throw PostingException::invalidLine($lineNo, 'account not found.');
        }
        if (! $account->is_active) {
            throw PostingException::invalidLine($lineNo, "account {$account->code} {$account->name} is inactive.");
        }

        return $account;
    }

    private function partyOf(JournalEntryLine $line): ?Model
    {
        if (! $line->party_type) {
            return null;
        }

        $class = Relation::getMorphedModel($line->party_type);

        return $class::withTrashed()->find($line->party_id);
    }
}
