<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('accounts.create');
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'regex:/^\d{3,10}$/', 'unique:accounts,code'],
            'name' => ['required', 'string', 'max:150'],
            'type' => ['required', Rule::in(['asset', 'liability', 'equity', 'revenue', 'cogs', 'expense'])],
            'normal_balance' => ['required', Rule::in(['debit', 'credit'])],
            'sub_type' => ['nullable', 'string', 'max:50'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
