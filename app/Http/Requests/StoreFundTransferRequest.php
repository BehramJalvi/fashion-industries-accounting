<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFundTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can($this->route('fund_transfer') ? 'fund_transfers.update' : 'fund_transfers.create') ?? false;
    }

    public function rules(): array
    {
        $cashOrBank = Rule::exists('accounts', 'id')->where(fn ($q) => $q->whereIn('sub_type', ['cash', 'bank'])->where('is_active', true));

        return [
            'transfer_date' => ['required', 'date'],
            'from_account_id' => ['required', 'integer', $cashOrBank],
            'to_account_id' => ['required', 'integer', 'different:from_account_id', $cashOrBank],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:9999999999999', 'regex:/^\d+(?:\.\d{1,2})?$/'],
            'reference' => ['nullable', 'string', 'max:60'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'action' => ['nullable', 'in:draft,post'],
        ];
    }
}
