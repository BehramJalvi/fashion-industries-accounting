<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('accounts.update');
    }

    /** Code, type and normal balance are fixed once created (history depends on them). */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'sub_type' => ['nullable', 'string', 'max:50'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
