<?php

namespace App\Http\Requests;

class UpdateProductRequest extends StoreProductRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('products.update');
    }

    public function rules(): array
    {
        return $this->productRules($this->route('product')->id) + ['remove_image' => ['sometimes', 'boolean']];
    }
}
