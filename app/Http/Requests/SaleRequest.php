<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Used for both creating and editing a draft sale. */
class SaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can($this->route('sale') ? 'sales.update' : 'sales.create');
    }

    public function rules(): array
    {
        $cashOrBank = Rule::exists('accounts', 'id')->where(fn ($q) => $q->whereIn('sub_type', ['cash', 'bank'])->where('is_active', true));
        $activeProduct = Rule::exists('products', 'id')->where(fn ($q) => $q->where('is_active', true)->whereNull('deleted_at'));

        return [
            'customer_id' => ['required', 'integer', Rule::exists('customers', 'id')->where(fn ($q) => $q->where('is_active', true)->whereNull('deleted_at'))],
            'sale_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:sale_date'],
            'gate_pass_no' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'received_now' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
            'received_into_account_id' => ['nullable', 'integer', $cashOrBank],
            'action' => ['nullable', 'in:draft,post'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.product_id' => ['required', 'integer', $activeProduct],
            'items.*.description' => ['nullable', 'string', 'max:200'],
            'items.*.qty' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'items.*.discount' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
            'items.*.tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
