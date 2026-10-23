<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PurchaseReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('purchase_returns.create');
    }

    public function rules(): array
    {
        return [
            'purchase_id' => ['required', 'integer', Rule::exists('purchases', 'id')->where('status', 'posted')],
            'return_date' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'refund_now' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
            'refund_account_id' => ['nullable', 'integer', Rule::exists('accounts', 'id')->where(fn ($q) => $q->whereIn('sub_type', ['cash', 'bank'])->where('is_active', true))],
            'action' => ['nullable', 'in:draft,post'],
            'qty' => ['required', 'array'],
            'qty.*' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
        ];
    }
}
