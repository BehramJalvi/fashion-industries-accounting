<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('products.create');
    }

    public function rules(): array
    {
        return $this->productRules() + [
            // optional opening stock, recorded together with the product
            'opening_qty' => ['nullable', 'numeric', 'gt:0', 'max:999999999'],
            'opening_unit_cost' => ['required_with:opening_qty', 'nullable', 'numeric', 'gt:0', 'max:999999999'],
            'opening_date' => ['required_with:opening_qty', 'nullable', 'date'],
        ];
    }

    protected function productRules(?int $ignoreId = null): array
    {
        return [
            'code' => ['nullable', 'string', 'max:30', Rule::unique('products', 'code')->ignore($ignoreId)],
            'name' => ['required', 'string', 'max:150'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'unit_id' => ['required', 'integer', 'exists:units,id'],
            'product_type' => ['required', Rule::in(['raw_material', 'finished_good', 'trading'])],
            'purchase_price' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'selling_price' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'default_tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'min_stock' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'description' => ['nullable', 'string', 'max:2000'],
            'image' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
