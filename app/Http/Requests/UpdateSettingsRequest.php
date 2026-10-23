<?php

namespace App\Http\Requests;

use App\Models\Sale;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('settings.manage');
    }

    public function rules(): array
    {
        return [
            'company_name' => ['required', 'string', 'max:150'],
            'company_tagline' => ['nullable', 'string', 'max:200'],
            'company_address' => ['nullable', 'string', 'max:500'],
            'company_phone' => ['nullable', 'string', 'max:60'],
            'company_email' => ['nullable', 'email', 'max:150'],
            'company_ntn' => ['nullable', 'string', 'max:30'],
            'company_strn' => ['nullable', 'string', 'max:30'],
            'logo' => ['nullable', 'file', 'image', 'mimes:png,jpg,jpeg,webp', 'max:1024'],   // no SVG: it can carry scripts
            'remove_logo' => ['sometimes', 'boolean'],

            'invoice_title' => ['required', 'string', 'max:60'],
            'invoice_footer' => ['nullable', 'string', 'max:300'],
            'invoice_min_rows' => ['required', 'integer', 'min:0', 'max:30'],
            'invoice_show_payment' => ['sometimes', 'boolean'],

            'default_sales_tax_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'allow_negative_stock' => ['sometimes', 'boolean'],

            'sale_prefix' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9\-\/]*$/'],
            'sale_next_number' => ['required', 'integer', 'min:1', 'max:999999999'],
            'sale_padding' => ['required', 'integer', 'min:1', 'max:8'],
        ];
    }

    /** The next invoice number must never collide with one already used. */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if ($v->errors()->hasAny(['sale_prefix', 'sale_next_number', 'sale_padding'])) {
                return;
            }

            $prefix = (string) $this->input('sale_prefix', '');
            $next = (int) $this->input('sale_next_number');

            $highest = Sale::withTrashed()->where('invoice_no', 'like', $prefix . '%')->pluck('invoice_no')
                ->map(fn ($n) => ctype_digit(substr($n, strlen($prefix))) ? (int) substr($n, strlen($prefix)) : 0)->max() ?? 0;
            if ($next <= $highest) {
                $v->errors()->add('sale_next_number', "The next invoice number must be higher than the highest one already used ({$highest}).");

                return;
            }

            $candidate = $prefix . str_pad((string) $next, (int) $this->input('sale_padding'), '0', STR_PAD_LEFT);
            if (Sale::withTrashed()->where('invoice_no', $candidate)->exists()) {
                $v->errors()->add('sale_next_number', "Invoice number {$candidate} already exists.");
            }
        });
    }
}
