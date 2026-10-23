<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreJournalRequest extends FormRequest
{
    public function authorize(): bool
    {
        // A manual journal posts immediately, so both abilities are needed.
        return $this->user()->can('journal.create') && $this->user()->can('journal.post');
    }

    public function rules(): array
    {
        return [
            'entry_date' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:60'],
            'description' => ['required', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.account_id' => ['required', 'integer', 'exists:accounts,id'],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
            'lines.*.party' => ['nullable', 'regex:/^(customer|supplier|employee|owner):\d+$/'],
            'lines.*.narration' => ['nullable', 'string', 'max:255'],
        ];
    }
}
