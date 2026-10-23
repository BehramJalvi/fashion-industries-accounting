<?php

namespace App\Services\Payments;

use App\Models\Account;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\NumberSequence;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Purchase;
use App\Models\Receipt;
use App\Models\Sale;
use App\Models\Supplier;
use App\Services\Accounting\PostingService;
use App\Support\AccountCodes;
use App\Support\Money;
use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Receipts (money in from a customer) and payments (money out to a supplier or employee).
 *
 *   receipt:  Dr Cash/Bank / Cr Accounts Receivable (customer)
 *   payment:  Dr Accounts Payable (supplier) or Employee Payable (employee) / Cr Cash/Bank
 *
 * The whole amount always reaches the party's account. How much of it is applied to specific invoices / bills is recorded as
 * ALLOCATIONS; whatever is not allocated stays on account (a customer's advance, a supplier advance, an employee advance)
 * and can be applied later. Drafts hold their allocations switched off; posting re-checks what each invoice still owes under a row
 * lock, then switches them on. Cancelling switches them off again, so the invoices become unpaid, and reverses the journal.
 */
class SettlementService
{
    public function __construct(private PostingService $posting, private AllocationService $allocations)
    {
    }

    /**
     * @param 'receipt'|'payment' $kind
     * @param array<string,mixed> $header  receipt: customer_id, receipt_date | payment: payee_type, payee_id, payment_date | both: method, account_id, amount, reference, notes
     * @param array<int,string|float|int|null> $requested  document id => amount to apply (ignored when $auto)
     */
    public function createDraft(string $kind, array $header, array $requested = [], bool $auto = false): Receipt|Payment
    {
        $amount = Money::toCents($header['amount'] ?? 0);
        if ($amount <= 0) {
            throw new DomainException('The amount must be greater than zero.');
        }
        $this->assertCashOrBank($header['account_id'] ?? null);

        $open = $this->openFor($kind, $kind === 'receipt' ? $this->customer($header['customer_id'])->id : $this->payee($header['payee_type'], $header['payee_id'])->id, $header['payee_type'] ?? null);
        $split = $auto ? $this->allocations->auto($open, $amount) : $this->allocations->normalise($requested, $open, $amount);

        return DB::transaction(function () use ($kind, $header, $amount, $split) {
            $common = [
                'method' => $header['method'] ?? 'cash', 'account_id' => $header['account_id'], 'amount' => Money::fromCents($amount),
                'reference' => $header['reference'] ?? null, 'notes' => $header['notes'] ?? null,
            ];
            $doc = $kind === 'receipt'
                ? new Receipt($common + ['receipt_no' => NumberSequence::next('receipt'), 'customer_id' => $header['customer_id'], 'receipt_date' => Carbon::parse($header['receipt_date'])->toDateString()])
                : new Payment($common + ['payment_no' => NumberSequence::next('payment'), 'payee_type' => $header['payee_type'], 'payee_id' => $header['payee_id'], 'payment_date' => Carbon::parse($header['payment_date'])->toDateString()]);
            $doc->forceFill(['status' => 'draft', 'created_by' => Auth::id()])->save();

            foreach ($split as $targetId => $cents) {
                $this->writeAllocation($kind, $doc->id, $targetId, $cents, false);
            }

            return $doc;
        });
    }

    public function post(Receipt|Payment $document): Receipt|Payment
    {
        return DB::transaction(function () use ($document) {
            $kind = $this->kind($document);
            $doc = $document::lockForUpdate()->findOrFail($document->id);
            if (! $doc->isDraft()) {
                throw new DomainException('Only a draft can be posted.');
            }

            $rows = PaymentAllocation::where('document_type', $kind)->where('document_id', $doc->id)->get();
            $this->lockTargets($kind, $rows->pluck('target_id')->all());

            // what each invoice / bill still owes NOW (another receipt or payment may have been posted since this draft was saved)
            $open = $this->openFor($kind, $this->partyId($doc), $doc->payee_type ?? null)->keyBy(fn ($r) => $r['model']->id);
            foreach ($rows as $row) {
                $owes = $open[$row->target_id]['balance'] ?? 0;
                if (Money::toCents($row->amount) > $owes) {
                    $label = $open[$row->target_id]['label'] ?? "document #{$row->target_id}";
                    throw new DomainException(sprintf('%s now only has %s outstanding (it changed since this draft was saved).', $label, Money::fromCents($owes)));
                }
            }

            $entry = $this->posting->post($this->journal($kind, $doc));
            PaymentAllocation::where('document_type', $kind)->where('document_id', $doc->id)->update(['is_active' => true]);
            $doc->forceFill(['status' => 'posted', 'journal_entry_id' => $entry->id, 'posted_at' => now(), 'updated_by' => Auth::id()])->save();

            return $doc;
        });
    }

    public function cancel(Receipt|Payment $document, string $reason): Receipt|Payment
    {
        return DB::transaction(function () use ($document, $reason) {
            $kind = $this->kind($document);
            $doc = $document::lockForUpdate()->findOrFail($document->id);
            if (! $doc->isPosted()) {
                throw new DomainException('Only a posted ' . $kind . ' can be cancelled.');
            }

            $reversal = $this->posting->reverse($doc->journalEntry, $reason);
            PaymentAllocation::where('document_type', $kind)->where('document_id', $doc->id)->update(['is_active' => false]);   // invoices / bills become unpaid again

            $doc->forceFill([
                'status' => 'cancelled', 'reversal_entry_id' => $reversal->id, 'cancelled_at' => now(),
                'cancelled_by' => Auth::id(), 'cancel_reason' => $reason, 'updated_by' => Auth::id(),
            ])->save();

            return $doc;
        });
    }

    /**
     * Apply money that is sitting on account (not yet allocated) to invoices / bills, e.g. an advance received before the invoice existed.
     *
     * @param array<int,string|float|int|null> $requested document id => amount
     */
    public function applyLater(Receipt|Payment $document, array $requested): int
    {
        return DB::transaction(function () use ($document, $requested) {
            $kind = $this->kind($document);
            $doc = $document::lockForUpdate()->findOrFail($document->id);
            if (! $doc->isPosted()) {
                throw new DomainException('Only a posted ' . $kind . ' can be applied to documents.');
            }

            $left = $doc->unallocatedCents();
            if ($left <= 0) {
                throw new DomainException('This ' . $kind . ' has already been applied in full.');
            }

            $ids = array_keys(array_filter($requested, fn ($v) => Money::toCents($v) !== 0));
            $this->lockTargets($kind, $ids);
            $open = $this->openFor($kind, $this->partyId($doc), $doc->payee_type ?? null);
            $split = $this->allocations->normalise($requested, $open, $left);
            if (! $split) {
                throw new DomainException('Enter an amount for at least one document.');
            }

            foreach ($split as $targetId => $cents) {
                $this->writeAllocation($kind, $doc->id, $targetId, $cents, true);
            }

            return array_sum($split);
        });
    }

    /** Drafts only. Their (switched-off) allocations go with them. */
    public function deleteDraft(Receipt|Payment $document): void
    {
        $kind = $this->kind($document);
        if (! $document->isDraft()) {
            throw new DomainException('Only a draft can be deleted. Cancel a posted ' . $kind . ' instead.');
        }

        DB::transaction(function () use ($kind, $document) {
            PaymentAllocation::where('document_type', $kind)->where('document_id', $document->id)->delete();
            $document->delete();
        });
    }

    /** Open invoices (receipt) or bills (payment) for the party of a document or header. */
    public function openFor(string $kind, int $partyId, ?string $payeeType = null): Collection
    {
        return $kind === 'receipt' ? $this->allocations->openSales($partyId) : $this->allocations->openPurchases($payeeType ?? 'supplier', $partyId);
    }

    // ------------------------------------------------------------------ internals

    private function kind(Receipt|Payment $d): string
    {
        return $d instanceof Receipt ? 'receipt' : 'payment';
    }

    private function partyId(Receipt|Payment $d): int
    {
        return $d instanceof Receipt ? (int) $d->customer_id : (int) $d->payee_id;
    }

    private function customer(mixed $id): Customer
    {
        return Customer::findOrFail($id);
    }

    private function payee(mixed $type, mixed $id): Supplier|Employee
    {
        return match ($type) {
            'supplier' => Supplier::findOrFail($id),
            'employee' => Employee::findOrFail($id),
            default => throw new DomainException('Choose who is being paid: a supplier or an employee.'),
        };
    }

    private function assertCashOrBank(mixed $accountId): void
    {
        $account = $accountId ? Account::find($accountId) : null;
        if (! $account || ! $account->is_active || ! in_array($account->sub_type, ['cash', 'bank'], true)) {
            throw new DomainException('Choose the cash or bank account the money goes through.');
        }
    }

    private function lockTargets(string $kind, array $ids): void
    {
        if ($ids) {
            ($kind === 'receipt' ? Sale::class : Purchase::class)::whereIn('id', $ids)->lockForUpdate()->get();
        }
    }

    private function writeAllocation(string $kind, int $documentId, int $targetId, int $cents, bool $active): void
    {
        PaymentAllocation::create([
            'document_type' => $kind, 'document_id' => $documentId, 'target_type' => $kind === 'receipt' ? 'sale' : 'purchase',
            'target_id' => $targetId, 'amount' => Money::fromCents($cents), 'is_active' => $active, 'created_by' => Auth::id(),
        ]);
    }

    private function journal(string $kind, Receipt|Payment $d): array
    {
        if ($kind === 'receipt') {
            $customer = Customer::findOrFail($d->customer_id);

            return [
                'date' => $d->receipt_date, 'voucher_type' => 'receipt', 'reference' => $d->reference ?: $d->receipt_no, 'source' => $d,
                'description' => "Receipt {$d->receipt_no} from {$customer->name}",
                'lines' => [
                    ['account' => (int) $d->account_id, 'debit' => $d->amount],
                    ['account' => AccountCodes::RECEIVABLE, 'credit' => $d->amount, 'party' => $customer],
                ],
            ];
        }

        $payee = $this->payee($d->payee_type, $d->payee_id);

        return [
            'date' => $d->payment_date, 'voucher_type' => 'payment', 'reference' => $d->reference ?: $d->payment_no, 'source' => $d,
            'description' => "Payment {$d->payment_no} to {$payee->name}",
            'lines' => [
                ['account' => $d->payee_type === 'employee' ? AccountCodes::EMPLOYEE_PAYABLE : AccountCodes::PAYABLE, 'debit' => $d->amount, 'party' => $payee],
                ['account' => (int) $d->account_id, 'credit' => $d->amount],
            ],
        ];
    }
}
