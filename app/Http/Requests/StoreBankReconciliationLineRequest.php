<?php

namespace App\Http\Requests;

use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StoreBankReconciliationLineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('bank_reconciliations.update');
    }

    public function rules(): array
    {
        return [
            'statement_date' => ['required', 'date_format:Y-m-d'],
            'description' => ['required', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:60'],
            'signed_amount' => ['required', 'numeric', 'decimal:0,2', 'between:-9999999999999,9999999999999', 'regex:/^-?\d+(?:\.\d{1,2})?$/',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (is_numeric($value) && Money::toCents((string) $value) === 0) {
                        $fail('The statement amount must be non-zero.');
                    }
                }],
        ];
    }
}
