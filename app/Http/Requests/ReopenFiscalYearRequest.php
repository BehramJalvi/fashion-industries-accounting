<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReopenFiscalYearRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('fiscal_years.reopen') ?? false;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:255'],
        ];
    }
}
