<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Used for both creating and editing a draft purchase. */
class PurchaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        $purchase = $this->route('purchase');

        return $this->user()->can($purchase ? 'purchases.update' : 'purchases.create');
    }

    public function rules(): array
    {
        $cashOrBank = Rule::exists('accounts', 'id')->where(fn ($q) => $q->whereIn('sub_type', ['cash', 'bank'])->where('is_active', true));
        $activeProduct = Rule::exists('products', 'id')->where(fn ($q) => $q->where('is_active', true)->whereNull('deleted_at'));

        return [
            'supplier_id' => ['nullable', 'integer', Rule::exists('suppliers', 'id')->where(fn ($q) => $q->where('is_active', true)->whereNull('deleted_at'))],
            'funded_by_employee_id' => ['nullable', 'integer', Rule::exists('employees', 'id')->where(fn ($q) => $q->whereNull('deleted_at'))],
            'purchase_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:purchase_date'],
            'supplier_ref' => ['nullable', 'string', 'max:60'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'paid_now' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
            'paid_from_account_id' => ['nullable', 'integer', $cashOrBank],
            'action' => ['nullable', 'in:draft,post'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.product_id' => ['required', 'integer', $activeProduct],
            'items.*.qty' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'items.*.discount' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
            'items.*.tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
