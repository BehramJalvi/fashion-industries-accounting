<?php

namespace App\Http\Requests;

use App\Models\SalaryPeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreSalaryPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('payroll.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'alpha_dash', 'max:20', 'unique:salary_periods,name'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'working_days' => ['required', 'integer', 'min:1', 'max:366'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->hasAny(['start_date', 'end_date'])) {
                    return;
                }

                $overlaps = SalaryPeriod::query()
                    ->whereDate('start_date', '<=', $this->date('end_date')->toDateString())
                    ->whereDate('end_date', '>=', $this->date('start_date')->toDateString())
                    ->exists();
                if ($overlaps) {
                    $validator->errors()->add('start_date', 'Salary periods cannot overlap.');
                }
            },
        ];
    }
}
