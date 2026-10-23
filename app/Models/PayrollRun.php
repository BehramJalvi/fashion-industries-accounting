<?php

namespace App\Models;

use App\Models\Concerns\IsPostable;
use Database\Factories\PayrollRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PayrollRun extends Model
{
    /** @use HasFactory<PayrollRunFactory> */
    use HasFactory, IsPostable, SoftDeletes;

    protected $fillable = ['salary_period_id', 'run_no'];

    protected function casts(): array
    {
        return ['posted_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(SalaryPeriod::class, 'salary_period_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PayrollItem::class);
    }
}
