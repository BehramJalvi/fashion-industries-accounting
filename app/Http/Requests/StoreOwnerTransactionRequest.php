<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOwnerTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('owner_transactions.create');
    }

    public function rules(): array
    {
        return [
            'owner_id' => ['required', 'integer', 'exists:owners,id'],
            'txn_type' => ['required', Rule::in(['capital', 'drawing'])],
            'txn_date' => ['required', 'date'],
            // only active cash / bank accounts may receive or pay out owner money
            'account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where(fn ($q) => $q->whereIn('sub_type', ['cash', 'bank'])->where('is_active', true))],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999999'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'action' => ['nullable', 'in:draft,post'],
        ];
    }
}
