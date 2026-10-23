<?php

namespace App\Services\Accounting;

use App\Models\NumberSequence;
use App\Models\OwnerTransaction;
use App\Support\AccountCodes;
use DomainException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Owner capital introduced / drawings taken. The owner is a party on the equity account, never a customer or supplier.
 *   capital : Dr Cash/Bank            / Cr Owner Capital (owner)
 *   drawing : Dr Owner Drawings (owner) / Cr Cash/Bank
 * Lifecycle draft -> posted -> cancelled; posting and cancelling are atomic with the journal.
 */
class OwnerTransactionService
{
    public function __construct(private PostingService $posting)
    {
    }

    /** @param array{owner_id:int, txn_type:string, txn_date:string, account_id:int, amount:string|float|int, notes?:?string} $data */
    public function createDraft(array $data): OwnerTransaction
    {
        return DB::transaction(function () use ($data) {
            $txn = new OwnerTransaction($data + ['txn_no' => NumberSequence::next('owner_transaction')]);
            $txn->forceFill(['status' => 'draft', 'created_by' => Auth::id()])->save();

            return $txn;
        });
    }

    public function post(OwnerTransaction $transaction): OwnerTransaction
    {
        return DB::transaction(function () use ($transaction) {
            $txn = OwnerTransaction::with('owner')->lockForUpdate()->findOrFail($transaction->id);
            if (! $txn->isDraft()) {
                throw new DomainException('Only a draft can be posted.');
            }

            $isCapital = $txn->txn_type === 'capital';
            $ownerLine = ['account' => $isCapital ? AccountCodes::OWNER_CAPITAL : AccountCodes::OWNER_DRAWINGS, 'party' => $txn->owner]
                + ($isCapital ? ['credit' => $txn->amount] : ['debit' => $txn->amount]);
            $cashLine = ['account' => (int) $txn->account_id] + ($isCapital ? ['debit' => $txn->amount] : ['credit' => $txn->amount]);

            $entry = $this->posting->post([
                'date' => $txn->txn_date, 'voucher_type' => 'owner', 'reference' => $txn->txn_no, 'source' => $txn,
                'description' => ($isCapital ? 'Capital introduced by ' : 'Drawings taken by ') . $txn->owner->name,
                'lines' => $isCapital ? [$cashLine, $ownerLine] : [$ownerLine, $cashLine],
            ]);

            $txn->forceFill(['status' => 'posted', 'journal_entry_id' => $entry->id, 'posted_at' => now(), 'updated_by' => Auth::id()])->save();

            return $txn;
        });
    }

    public function cancel(OwnerTransaction $transaction, string $reason): OwnerTransaction
    {
        return DB::transaction(function () use ($transaction, $reason) {
            $txn = OwnerTransaction::lockForUpdate()->findOrFail($transaction->id);
            if (! $txn->isPosted()) {
                throw new DomainException('Only a posted transaction can be cancelled.');
            }

            $reversal = $this->posting->reverse($txn->journalEntry, $reason);
            $txn->forceFill([
                'status' => 'cancelled', 'reversal_entry_id' => $reversal->id, 'cancelled_at' => now(),
                'cancelled_by' => Auth::id(), 'cancel_reason' => $reason, 'updated_by' => Auth::id(),
            ])->save();

            return $txn;
        });
    }
}
