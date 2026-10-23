<?php

namespace App\Models;

use Database\Factories\RecurringTransactionExecutionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class RecurringTransactionExecution extends Model
{
    /** @use HasFactory<RecurringTransactionExecutionFactory> */
    use HasFactory;

    protected $fillable = [
        'template_id',
        'scheduled_for',
        'transaction_type',
        'document_type',
        'document_id',
        'status',
        'created_by',
        'generated_at',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_for' => 'date',
            'generated_at' => 'datetime',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(RecurringTransactionTemplate::class, 'template_id');
    }

    public function document(): MorphTo
    {
        return $this->morphTo();
    }
}
