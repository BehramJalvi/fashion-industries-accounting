<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JournalEntryLine extends Model
{
    protected $fillable = [
        'journal_entry_id', 'line_no', 'account_id', 'debit', 'credit',
        'party_type', 'party_id', 'product_id', 'narration',
    ];

    protected function casts(): array
    {
        return ['debit' => 'decimal:2', 'credit' => 'decimal:2'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new DomainException('Journal lines are immutable.'));
        static::deleting(fn () => throw new DomainException('Journal lines cannot be deleted.'));
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function reconciliationMatches(): HasMany
    {
        return $this->hasMany(BankReconciliationLine::class, 'matched_journal_entry_line_id');
    }
}
