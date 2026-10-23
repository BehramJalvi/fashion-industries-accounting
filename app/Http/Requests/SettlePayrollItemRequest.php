<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SettlePayrollItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('payroll.settle') ?? false;
    }

    public function rules(): array
    {
        return [
            'payment_date' => ['required', 'date'],
            'method' => ['required', Rule::in(['cash', 'bank', 'cheque', 'online'])],
            'account_id' => [
                'required',
                'integer',
                Rule::exists('accounts', 'id')->where(fn ($query) => $query->whereIn('sub_type', ['cash', 'bank'])->where('is_active', true)),
            ],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999999'],
            'reference' => ['nullable', 'string', 'max:60'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }
}
