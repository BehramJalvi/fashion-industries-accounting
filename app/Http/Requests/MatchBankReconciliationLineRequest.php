<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MatchBankReconciliationLineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('bank_reconciliations.update') ?? false;
    }

    public function rules(): array
    {
        return [
            'journal_entry_line_id' => ['required', 'integer', 'exists:journal_entry_lines,id'],
        ];
    }
}
