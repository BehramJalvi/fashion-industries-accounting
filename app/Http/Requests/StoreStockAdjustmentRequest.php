<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreStockAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('stock_adjustments.create');
    }

    public function rules(): array
    {
        return [
            'adjustment_date' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'action' => ['nullable', 'in:draft,post'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.direction' => ['required', 'in:in,out'],
            'items.*.qty' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'items.*.unit_cost' => ['nullable', 'numeric', 'min:0', 'max:999999999', 'required_if:items.*.direction,in'],
        ];
    }

    public function messages(): array
    {
        return ['items.*.unit_cost.required_if' => 'Enter the unit cost for stock coming in.'];
    }
}
