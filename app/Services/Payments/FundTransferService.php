<?php

namespace App\Services\Payments;

use App\Models\Account;
use App\Models\FundTransfer;
use App\Models\NumberSequence;
use App\Services\Accounting\PostingService;
use App\Support\Money;
use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/** Money moved between the business's own cash and bank accounts (e.g. a cheque cashed at the bank): Dr receiving account / Cr paying account. No profit or loss. */
class FundTransferService
{
    public function __construct(private PostingService $posting) {}

    /** @param array{transfer_date:string, from_account_id:int, to_account_id:int, amount:string|float|int, reference?:?string, notes?:?string} $h */
    public function createDraft(array $h): FundTransfer
    {
        $amount = $this->validatedAmount($h);

        return DB::transaction(function () use ($h, $amount) {
            $t = new FundTransfer([
                'transfer_no' => NumberSequence::next('fund_transfer'), 'transfer_date' => Carbon::parse($h['transfer_date'])->toDateString(),
                'from_account_id' => $h['from_account_id'], 'to_account_id' => $h['to_account_id'], 'amount' => Money::fromCents($amount),
                'reference' => $h['reference'] ?? null, 'notes' => $h['notes'] ?? null,
            ]);
            $t->forceFill(['status' => 'draft', 'created_by' => Auth::id()])->save();

            return $t;
        });
    }

    /** @param array<string,mixed> $h */
    public function updateDraft(FundTransfer $transfer, array $h): FundTransfer
    {
        $amount = $this->validatedAmount($h);

        return DB::transaction(function () use ($transfer, $h, $amount) {
            $record = FundTransfer::query()->lockForUpdate()->findOrFail($transfer->id);
            if (! $record->isDraft()) {
                throw new DomainException('Only a draft transfer can be edited.');
            }

            $record->fill([
                'transfer_date' => Carbon::parse($h['transfer_date'])->toDateString(),
                'from_account_id' => (int) $h['from_account_id'],
                'to_account_id' => (int) $h['to_account_id'],
                'amount' => Money::fromCents($amount),
                'reference' => $h['reference'] ?? null,
                'notes' => $h['notes'] ?? null,
            ]);
            $record->forceFill(['updated_by' => Auth::id()])->save();

            return $record;
        });
    }

    public function post(FundTransfer $transfer): FundTransfer
    {
        return DB::transaction(function () use ($transfer) {
            $t = FundTransfer::with(['fromAccount', 'toAccount'])->lockForUpdate()->findOrFail($transfer->id);
            if (! $t->isDraft()) {
                throw new DomainException('Only a draft transfer can be posted.');
            }

            $entry = $this->posting->post([
                'date' => $t->transfer_date, 'voucher_type' => 'transfer', 'reference' => $t->reference ?: $t->transfer_no, 'source' => $t,
                'description' => "Transfer {$t->transfer_no}: {$t->fromAccount->name} to {$t->toAccount->name}",
                'lines' => [
                    ['account' => (int) $t->to_account_id, 'debit' => $t->amount],
                    ['account' => (int) $t->from_account_id, 'credit' => $t->amount],
                ],
            ]);

            $t->forceFill(['status' => 'posted', 'journal_entry_id' => $entry->id, 'posted_at' => now(), 'updated_by' => Auth::id()])->save();

            return $t;
        });
    }

    public function cancel(FundTransfer $transfer, string $reason): FundTransfer
    {
        return DB::transaction(function () use ($transfer, $reason) {
            $t = FundTransfer::lockForUpdate()->findOrFail($transfer->id);
            if (! $t->isPosted()) {
                throw new DomainException('Only a posted transfer can be cancelled.');
            }

            $reversal = $this->posting->reverse($t->journalEntry, $reason);
            $t->forceFill([
                'status' => 'cancelled', 'reversal_entry_id' => $reversal->id, 'cancelled_at' => now(),
                'cancelled_by' => Auth::id(), 'cancel_reason' => $reason, 'updated_by' => Auth::id(),
            ])->save();

            return $t;
        });
    }

    /** @param array<string,mixed> $h */
    private function validatedAmount(array $h): int
    {
        $amount = Money::toCents($h['amount'] ?? 0);
        if ($amount <= 0) {
            throw new DomainException('The amount must be greater than zero.');
        }
        if ((int) $h['from_account_id'] === (int) $h['to_account_id']) {
            throw new DomainException('Choose two different accounts.');
        }
        foreach (['from_account_id' => 'paying', 'to_account_id' => 'receiving'] as $field => $label) {
            $account = Account::find($h[$field] ?? null);
            if (! $account || ! $account->is_active || ! in_array($account->sub_type, ['cash', 'bank'], true)) {
                throw new DomainException("The {$label} account must be an active cash or bank account.");
            }
        }

        return $amount;
    }
}
