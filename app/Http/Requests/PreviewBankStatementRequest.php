<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PreviewBankStatementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('bank_reconciliations.create') ?? false;
    }

    public function rules(): array
    {
        $bankAccount = Rule::exists('accounts', 'id')->where(
            fn ($query) => $query->where('sub_type', 'bank')->where('is_active', true)
        );

        return [
            'account_id' => ['required', 'integer', $bankAccount],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'opening_balance' => ['required', 'numeric', 'decimal:0,2', 'between:-9999999999999,9999999999999', 'regex:/^-?\d+(?:\.\d{1,2})?$/'],
            'closing_balance' => ['required', 'numeric', 'decimal:0,2', 'between:-9999999999999,9999999999999', 'regex:/^-?\d+(?:\.\d{1,2})?$/'],
            'statement_file' => ['required', 'file', 'mimes:csv,txt', 'extensions:csv,txt', 'max:2048'],
        ];
    }
}
