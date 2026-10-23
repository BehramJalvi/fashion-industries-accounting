<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class StoreRecurringTransactionTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('recurring_transactions.create') ?? false;
    }

    public function rules(): array
    {
        $isExpense = $this->input('transaction_type') === 'expense';
        $expenseAccount = Rule::exists('accounts', 'id')->where(
            fn ($query) => $query->whereIn('type', ['expense', 'cogs'])->where('is_active', true)->whereNull('party_type')
        );
        $cashOrBank = Rule::exists('accounts', 'id')->where(
            fn ($query) => $query->whereIn('sub_type', ['cash', 'bank'])->where('is_active', true)
        );

        return [
            'name' => ['required', 'string', 'max:120'],
            'transaction_type' => ['required', Rule::in(['expense', 'fund_transfer'])],
            'frequency' => ['required', Rule::in(['weekly', 'monthly', 'yearly'])],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'expense_account_id' => $isExpense ? ['required', 'integer', $expenseAccount] : ['prohibited'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999999'],
            'tax_amount' => $isExpense ? ['nullable', 'numeric', 'min:0', 'max:9999999999999'] : ['prohibited'],
            'description' => $isExpense ? ['required', 'string', 'max:255'] : ['prohibited'],
            'paid_from_account_id' => $isExpense
                ? ['nullable', 'required_without:owed_to', 'prohibited_with:owed_to', 'integer', $cashOrBank]
                : ['prohibited'],
            'owed_to' => $isExpense
                ? ['nullable', 'required_without:paid_from_account_id', 'prohibited_with:paid_from_account_id', 'regex:/^(supplier|employee):\d+$/',
                    function (string $attribute, mixed $value, \Closure $fail): void {
                        if (! is_string($value) || ! preg_match('/^(supplier|employee):(\d+)$/', $value, $matches)) {
                            return;
                        }

                        $table = $matches[1] === 'supplier' ? 'suppliers' : 'employees';
                        if (! DB::table($table)->where('id', $matches[2])->where('is_active', true)->exists()) {
                            $fail('Choose an active supplier or employee.');
                        }
                    }]
                : ['prohibited'],
            'from_account_id' => $isExpense ? ['prohibited'] : ['required', 'integer', $cashOrBank],
            'to_account_id' => $isExpense ? ['prohibited'] : ['required', 'integer', 'different:from_account_id', $cashOrBank],
            'reference' => $isExpense ? ['prohibited'] : ['nullable', 'string', 'max:60'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
