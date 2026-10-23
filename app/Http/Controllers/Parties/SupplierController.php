<?php

namespace App\Http\Controllers\Parties;

use App\Models\Supplier;
use Illuminate\Validation\Rule;

class SupplierController extends PartyController
{
    protected function model(): string { return Supplier::class; }
    protected function type(): string { return 'supplier'; }
    protected function prefix(): string { return 'suppliers'; }
    protected function codePrefix(): ?string { return 'S'; }
    protected function historyRelations(): array { return ['purchases']; }

    protected function labels(): array
    {
        return [
            'singular' => 'Supplier', 'plural' => 'Suppliers', 'balance' => 'Payable', 'positive' => 'We owe', 'negative' => 'Advance paid',
            'sides' => ['we_owe' => 'We owe supplier (payable)', 'owes_us' => 'Supplier owes us (advance paid)'],
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
            ['name' => 'payment_terms_days', 'label' => 'Payment terms (days)', 'type' => 'number', 'cols' => 3, 'default' => 30],
            ['name' => 'is_active', 'label' => 'Active', 'type' => 'checkbox', 'cols' => 3],
            ['name' => 'notes', 'label' => 'Notes', 'type' => 'textarea', 'cols' => 12],
        ];
    }

    protected function rules(?int $ignoreId): array
    {
        return [
            'code' => ['nullable', 'string', 'max:30', Rule::unique('suppliers', 'code')->ignore($ignoreId)],
            'name' => ['required', 'string', 'max:150'],
            'company' => ['nullable', 'string', 'max:150'],
            'contact_person' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
            'address' => ['nullable', 'string', 'max:500'],
            'ntn' => ['nullable', 'string', 'max:30'],
            'strn' => ['nullable', 'string', 'max:30'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
