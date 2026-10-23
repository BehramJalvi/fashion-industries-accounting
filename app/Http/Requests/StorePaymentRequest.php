<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('payments.create');
    }

    public function rules(): array
    {
        return [
            'payee_type' => ['required', Rule::in(['supplier', 'employee'])],
            'payee_id' => ['required', 'integer'],
            'payment_date' => ['required', 'date'],
            'method' => ['required', Rule::in(['cash', 'bank', 'cheque', 'online'])],
            'account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where(fn ($q) => $q->whereIn('sub_type', ['cash', 'bank'])->where('is_active', true))],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999999'],
            'reference' => ['nullable', 'string', 'max:60'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'auto_allocate' => ['sometimes', 'boolean'],
            'alloc' => ['nullable', 'array'],
            'alloc.*' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
            'action' => ['nullable', 'in:draft,post'],
        ];
    }
}
