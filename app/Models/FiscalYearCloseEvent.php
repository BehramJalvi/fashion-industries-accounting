<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FiscalYearCloseEvent extends Model
{
    protected $fillable = [
        'fiscal_year_id', 'cycle', 'event_type', 'journal_entry_id', 'reversal_entry_id', 'reason', 'created_by',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new DomainException('Fiscal-year close history is immutable.'));
        static::deleting(fn () => throw new DomainException('Fiscal-year close history cannot be deleted.'));
    }

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function reversalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'reversal_entry_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
