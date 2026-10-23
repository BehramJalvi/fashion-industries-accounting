<?php

namespace App\Services\Payments;

use App\Models\Account;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\NumberSequence;
use App\Models\Supplier;
use App\Services\Accounting\PostingService;
use App\Support\AccountCodes;
use App\Support\Money;
use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * An expense is either paid straight away from cash / bank, or left owing to a supplier or an employee:
 *   paid now:  Dr Expense (+ Dr Input Tax)  /  Cr Cash or Bank
 *   on credit: Dr Expense (+ Dr Input Tax)  /  Cr Accounts Payable (supplier)  or  Cr Employee Payable (employee: salary, or money he spent for us)
 * Pay what is owed later with a Payment; it reduces the party's balance.
 */
class ExpenseService
{
    public function __construct(private PostingService $posting) {}

    /** @param array<string,mixed> $h expense_date, expense_account_id, amount, tax_amount, description, notes, paid_from_account_id | payee_type + payee_id */
    public function createDraft(array $h): Expense
    {
        $details = $this->validatedDraftDetails($h);

        return DB::transaction(function () use ($h, $details) {
            $expense = new Expense([
                'expense_no' => NumberSequence::next('expense'), 'expense_date' => Carbon::parse($h['expense_date'])->toDateString(),
                'expense_account_id' => $details['expense_account_id'], 'paid_from_account_id' => $details['paid_from_account_id'],
                'payee_type' => $details['payee_type'], 'payee_id' => $details['payee_id'],
                'amount' => Money::fromCents($details['amount_cents']), 'tax_amount' => Money::fromCents($details['tax_cents']),
                'description' => $h['description'], 'notes' => $h['notes'] ?? null,
            ]);
            $expense->forceFill(['status' => 'draft', 'created_by' => Auth::id()])->save();

            return $expense;
        });
    }

    /** @param array<string,mixed> $h */
    public function updateDraft(Expense $expense, array $h): Expense
    {
        $details = $this->validatedDraftDetails($h);

        return DB::transaction(function () use ($expense, $h, $details) {
            $record = Expense::query()->lockForUpdate()->findOrFail($expense->id);
            if (! $record->isDraft()) {
                throw new DomainException('Only a draft expense can be edited.');
            }

            $record->fill([
                'expense_date' => Carbon::parse($h['expense_date'])->toDateString(),
                'expense_account_id' => $details['expense_account_id'],
                'paid_from_account_id' => $details['paid_from_account_id'],
                'payee_type' => $details['payee_type'],
                'payee_id' => $details['payee_id'],
                'amount' => Money::fromCents($details['amount_cents']),
                'tax_amount' => Money::fromCents($details['tax_cents']),
                'description' => $h['description'],
                'notes' => $h['notes'] ?? null,
            ]);
            $record->forceFill(['updated_by' => Auth::id()])->save();

            return $record;
        });
    }

    public function post(Expense $expense): Expense
    {
        return DB::transaction(function () use ($expense) {
            $e = Expense::lockForUpdate()->findOrFail($expense->id);
            if (! $e->isDraft()) {
                throw new DomainException('Only a draft expense can be posted.');
            }

            $net = Money::toCents($e->amount);
            $tax = Money::toCents($e->tax_amount);
            $total = Money::fromCents($net + $tax);

            $lines = [['account' => (int) $e->expense_account_id, 'debit' => $e->amount]];
            if ($tax > 0) {
                $lines[] = ['account' => AccountCodes::INPUT_TAX, 'debit' => $e->tax_amount];
            }
            if ($e->paid_from_account_id) {
                $lines[] = ['account' => (int) $e->paid_from_account_id, 'credit' => $total];
            } else {
                $payee = $this->payee($e->payee_type, $e->payee_id);
                $lines[] = ['account' => $e->payee_type === 'employee' ? AccountCodes::EMPLOYEE_PAYABLE : AccountCodes::PAYABLE, 'credit' => $total, 'party' => $payee];
            }

            $entry = $this->posting->post([
                'date' => $e->expense_date, 'voucher_type' => 'expense', 'reference' => $e->expense_no, 'source' => $e,
                'description' => "Expense {$e->expense_no}: {$e->description}", 'lines' => $lines,
            ]);

            $e->forceFill(['status' => 'posted', 'journal_entry_id' => $entry->id, 'posted_at' => now(), 'updated_by' => Auth::id()])->save();

            return $e;
        });
    }

    public function cancel(Expense $expense, string $reason): Expense
    {
        return DB::transaction(function () use ($expense, $reason) {
            $e = Expense::lockForUpdate()->findOrFail($expense->id);
            if (! $e->isPosted()) {
                throw new DomainException('Only a posted expense can be cancelled.');
            }

            $reversal = $this->posting->reverse($e->journalEntry, $reason);
            $e->forceFill([
                'status' => 'cancelled', 'reversal_entry_id' => $reversal->id, 'cancelled_at' => now(),
                'cancelled_by' => Auth::id(), 'cancel_reason' => $reason, 'updated_by' => Auth::id(),
            ])->save();

            return $e;
        });
    }

    private function payee(mixed $type, mixed $id): Supplier|Employee
    {
        return match ($type) {
            'supplier' => Supplier::find($id) ?? throw new DomainException('That supplier does not exist.'),
            'employee' => Employee::find($id) ?? throw new DomainException('That employee does not exist.'),
            default => throw new DomainException('Choose who it is owed to: a supplier or an employee.'),
        };
    }

    /**
     * @param  array<string,mixed>  $h
     * @return array{amount_cents:int, tax_cents:int, expense_account_id:int, paid_from_account_id:?int, payee_type:?string, payee_id:?int}
     */
    private function validatedDraftDetails(array $h): array
    {
        $amount = Money::toCents($h['amount'] ?? 0);
        $tax = Money::toCents($h['tax_amount'] ?? 0);
        if ($amount <= 0) {
            throw new DomainException('The expense amount must be greater than zero.');
        }
        if ($tax < 0) {
            throw new DomainException('The tax amount cannot be negative.');
        }

        $account = ! empty($h['expense_account_id']) ? Account::find($h['expense_account_id']) : null;
        if (! $account || ! $account->is_active || ! in_array($account->type, ['expense', 'cogs'], true) || $account->party_type) {
            throw new DomainException('Choose an active expense account.');
        }

        $paidFrom = ! empty($h['paid_from_account_id']) ? (int) $h['paid_from_account_id'] : null;
        $payeeType = $h['payee_type'] ?? null;
        $payeeId = isset($h['payee_id']) ? (int) $h['payee_id'] : null;
        if ($paidFrom && $payeeType) {
            throw new DomainException('Choose either the account it was paid from, or who it is owed to, not both.');
        }
        if (! $paidFrom && ! $payeeType) {
            throw new DomainException('Say how it was paid: pick the cash or bank account, or who it is owed to.');
        }
        if ($paidFrom) {
            $cash = Account::find($paidFrom);
            if (! $cash || ! $cash->is_active || ! in_array($cash->sub_type, ['cash', 'bank'], true)) {
                throw new DomainException('Choose the cash or bank account it was paid from.');
            }
        } else {
            $this->payee($payeeType, $payeeId);
        }

        return [
            'amount_cents' => $amount,
            'tax_cents' => $tax,
            'expense_account_id' => (int) $account->id,
            'paid_from_account_id' => $paidFrom,
            'payee_type' => $paidFrom ? null : $payeeType,
            'payee_id' => $paidFrom ? null : $payeeId,
        ];
    }
}
