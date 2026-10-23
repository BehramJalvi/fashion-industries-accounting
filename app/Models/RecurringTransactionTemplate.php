<?php

namespace App\Models;

use Database\Factories\RecurringTransactionTemplateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecurringTransactionTemplate extends Model
{
    /** @use HasFactory<RecurringTransactionTemplateFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'transaction_type',
        'payload',
        'frequency',
        'starts_on',
        'next_run_on',
        'ends_on',
        'is_active',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'starts_on' => 'date',
            'next_run_on' => 'date',
            'ends_on' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function executions(): HasMany
    {
        return $this->hasMany(RecurringTransactionExecution::class, 'template_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
