<?php

namespace App\Http\Controllers\Parties;

use App\Models\Customer;
use App\Models\Setting;
use Illuminate\Validation\Rule;

class CustomerController extends PartyController
{
    protected function model(): string { return Customer::class; }
    protected function type(): string { return 'customer'; }
    protected function prefix(): string { return 'customers'; }
    protected function codePrefix(): ?string { return 'C'; }
    protected function historyRelations(): array { return ['sales', 'receipts']; }

    protected function labels(): array
    {
        return [
            'singular' => 'Customer', 'plural' => 'Customers', 'balance' => 'Receivable', 'positive' => 'Owes us', 'negative' => 'Advance received',
            'sides' => ['owes_us' => 'Customer owes us (receivable)', 'we_owe' => 'We owe customer (advance received)'],
        ];
    }

    protected function columns(): array { return ['Code' => 'code', 'Name' => 'name', 'Company' => 'company', 'Phone' => 'phone']; }

    protected function fields(): array
    {
        return [
            ['name' => 'code', 'label' => 'Code', 'type' => 'text', 'cols' => 3, 'help' => 'Auto if blank'],
            ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'cols' => 5, 'required' => true],
            ['name' => 'company', 'label' => 'Company', 'type' => 'text', 'cols' => 4],
            ['name' => 'contact_person', 'label' => 'Contact person', 'type' => 'text', 'cols' => 4],
            ['name' => 'phone', 'label' => 'Phone', 'type' => 'text', 'cols' => 4],
            ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'cols' => 4],
            ['name' => 'address', 'label' => 'Address', 'type' => 'textarea', 'cols' => 12],
            ['name' => 'ntn', 'label' => 'NTN', 'type' => 'text', 'cols' => 3],
            ['name' => 'strn', 'label' => 'Sales tax reg. no. (STRN)', 'type' => 'text', 'cols' => 3],
            ['name' => 'cnic', 'label' => 'CNIC', 'type' => 'text', 'cols' => 3],
            ['name' => 'credit_limit', 'label' => 'Credit limit (PKR)', 'type' => 'number', 'cols' => 3, 'default' => 0],
            ['name' => 'payment_terms_days', 'label' => 'Payment terms (days)', 'type' => 'number', 'cols' => 3, 'default' => 30],
            ['name' => 'tax_rate', 'label' => 'Sales tax rate % (default for invoices)', 'type' => 'number', 'cols' => 4, 'default' => (float) Setting::get('default_sales_tax_rate', 18)],
            ['name' => 'is_tax_exempt', 'label' => 'Tax exempt (invoices at 0%)', 'type' => 'checkbox', 'cols' => 3],
            ['name' => 'is_active', 'label' => 'Active', 'type' => 'checkbox', 'cols' => 2],
            ['name' => 'notes', 'label' => 'Notes', 'type' => 'textarea', 'cols' => 12],
        ];
    }

    protected function rules(?int $ignoreId): array
    {
        return [
            'code' => ['nullable', 'string', 'max:30', Rule::unique('customers', 'code')->ignore($ignoreId)],
            'name' => ['required', 'string', 'max:150'],
            'company' => ['nullable', 'string', 'max:150'],
            'contact_person' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
            'address' => ['nullable', 'string', 'max:500'],
            'ntn' => ['nullable', 'string', 'max:30'],
            'strn' => ['nullable', 'string', 'max:30'],
            'cnic' => ['nullable', 'string', 'max:20'],
            'credit_limit' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'is_tax_exempt' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
