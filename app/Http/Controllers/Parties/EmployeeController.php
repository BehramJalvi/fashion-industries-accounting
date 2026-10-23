<?php

namespace App\Http\Controllers\Parties;

use App\Models\Employee;
use Illuminate\Validation\Rule;

/** Employees are two-way parties: credit = we owe them (salary, money they spent for us), debit = they owe us (advance). */
class EmployeeController extends PartyController
{
    protected function model(): string { return Employee::class; }
    protected function type(): string { return 'employee'; }
    protected function prefix(): string { return 'employees'; }
    protected function codePrefix(): ?string { return 'E'; }

    protected function labels(): array
    {
        return [
            'singular' => 'Employee', 'plural' => 'Employees', 'balance' => 'Net payable', 'positive' => 'We owe', 'negative' => 'Owes us (advance)',
            'sides' => ['we_owe' => 'We owe employee (salary / expenses to reimburse)', 'owes_us' => 'Employee owes us (advance given)'],
        ];
    }

    protected function columns(): array { return ['Code' => 'code', 'Name' => 'name', 'Designation' => 'designation', 'Phone' => 'phone']; }

    protected function fields(): array
    {
        return [
            ['name' => 'code', 'label' => 'Code', 'type' => 'text', 'cols' => 3, 'help' => 'Auto if blank'],
            ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'cols' => 5, 'required' => true],
            ['name' => 'designation', 'label' => 'Designation', 'type' => 'text', 'cols' => 4],
            ['name' => 'phone', 'label' => 'Phone', 'type' => 'text', 'cols' => 4],
            ['name' => 'monthly_salary', 'label' => 'Monthly salary (PKR)', 'type' => 'number', 'cols' => 4, 'default' => 0],
            ['name' => 'bank_account_no', 'label' => 'Bank account no.', 'type' => 'text', 'cols' => 4],
            ['name' => 'is_active', 'label' => 'Active', 'type' => 'checkbox', 'cols' => 12],
            ['name' => 'notes', 'label' => 'Notes', 'type' => 'textarea', 'cols' => 12],
        ];
    }

    protected function rules(?int $ignoreId): array
    {
        return [
            'code' => ['nullable', 'string', 'max:30', Rule::unique('employees', 'code')->ignore($ignoreId)],
            'name' => ['required', 'string', 'max:150'],
            'designation' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:30'],
            'monthly_salary' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
            'bank_account_no' => ['nullable', 'string', 'max:50'],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
