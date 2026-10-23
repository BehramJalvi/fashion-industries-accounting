<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can($this->route('expense') ? 'expenses.update' : 'expenses.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'expense_date' => ['required', 'date'],
            'expense_account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where(fn ($q) => $q->whereIn('type', ['expense', 'cogs'])->where('is_active', true)->whereNull('party_type'))],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:9999999999999', 'regex:/^\d+(?:\.\d{1,2})?$/'],
            'tax_amount' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999', 'regex:/^\d+(?:\.\d{1,2})?$/'],
            'description' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'paid_from_account_id' => ['nullable', 'integer', Rule::exists('accounts', 'id')->where(fn ($q) => $q->whereIn('sub_type', ['cash', 'bank'])->where('is_active', true))],
            'owed_to' => ['nullable', 'regex:/^(supplier|employee):\d+$/'],   // "supplier:3"
            'action' => ['nullable', 'in:draft,post'],
        ];
    }
}
