<?php

namespace App\Models;

use Database\Factories\SalaryPeriodFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SalaryPeriod extends Model
{
    /** @use HasFactory<SalaryPeriodFactory> */
    use HasFactory;

    protected $fillable = ['name', 'start_date', 'end_date', 'working_days'];

    protected function casts(): array
    {
        return ['start_date' => 'date', 'end_date' => 'date'];
    }

    public function run(): HasOne
    {
        return $this->hasOne(PayrollRun::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
