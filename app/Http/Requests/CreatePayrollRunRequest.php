<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreatePayrollRunRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('payroll.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'employees' => ['required', 'array', 'min:1', 'max:500'],
            'employees.*.include' => ['nullable', 'boolean'],
            'employees.*.present_days' => ['required', 'integer', 'min:0', 'max:366'],
            'employees.*.paid_leave_days' => ['nullable', 'integer', 'min:0', 'max:366'],
            'employees.*.unpaid_leave_days' => ['nullable', 'integer', 'min:0', 'max:366'],
            'employees.*.advance_adjustment' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
            'employees.*.allowances' => ['nullable', 'array', 'max:20'],
            'employees.*.allowances.*.description' => ['required_with:employees.*.allowances.*.amount', 'string', 'max:100'],
            'employees.*.allowances.*.amount' => ['required_with:employees.*.allowances.*.description', 'numeric', 'gt:0', 'max:9999999999999'],
            'employees.*.deductions' => ['nullable', 'array', 'max:20'],
            'employees.*.deductions.*.description' => ['required_with:employees.*.deductions.*.amount', 'string', 'max:100'],
            'employees.*.deductions.*.amount' => ['required_with:employees.*.deductions.*.description', 'numeric', 'gt:0', 'max:9999999999999'],
        ];
    }
}
