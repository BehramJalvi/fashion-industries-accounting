<?php

namespace App\Models;

use App\Support\Money;
use Database\Factories\PayrollItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollItem extends Model
{
    /** @use HasFactory<PayrollItemFactory> */
    use HasFactory;

    protected $fillable = [
        'payroll_run_id', 'employee_id', 'monthly_salary', 'present_days', 'paid_leave_days', 'unpaid_leave_days',
        'base_pay', 'allowance_total', 'deduction_total', 'advance_adjustment', 'net_pay',
    ];

    protected function casts(): array
    {
        return [
            'monthly_salary' => 'decimal:2', 'base_pay' => 'decimal:2', 'allowance_total' => 'decimal:2',
            'deduction_total' => 'decimal:2', 'advance_adjustment' => 'decimal:2', 'net_pay' => 'decimal:2',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function components(): HasMany
    {
        return $this->hasMany(PayrollComponent::class);
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(PayrollSettlement::class);
    }

    public function settledCents(): int
    {
        if ($this->relationLoaded('settlements')) {
            return $this->settlements
                ->filter(fn (PayrollSettlement $settlement): bool => $settlement->payment?->isPosted() ?? false)
                ->sum(fn (PayrollSettlement $settlement): int => Money::toCents($settlement->amount));
        }

        return Money::toCents($this->settlements()
            ->whereHas('payment', fn ($query) => $query->where('status', 'posted'))
            ->sum('amount'));
    }

    public function balanceCents(): int
    {
        return Money::toCents($this->net_pay) - $this->settledCents();
    }
}
