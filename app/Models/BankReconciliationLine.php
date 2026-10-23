<?php

namespace App\Models;

use Database\Factories\BankReconciliationLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankReconciliationLine extends Model
{
    /** @use HasFactory<BankReconciliationLineFactory> */
    use HasFactory;

    protected $fillable = [
        'reconciliation_id',
        'statement_date',
        'description',
        'reference',
        'signed_amount',
        'matched_journal_entry_line_id',
        'matched_by',
        'matched_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'statement_date' => 'date',
            'signed_amount' => 'decimal:2',
            'matched_at' => 'datetime',
        ];
    }

    public function reconciliation(): BelongsTo
    {
        return $this->belongsTo(BankReconciliation::class, 'reconciliation_id');
    }

    public function matchedJournalLine(): BelongsTo
    {
        return $this->belongsTo(JournalEntryLine::class, 'matched_journal_entry_line_id');
    }

    public function matcher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'matched_by');
    }
}
