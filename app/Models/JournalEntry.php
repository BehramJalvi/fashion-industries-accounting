<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Posted entries are immutable. The only permitted change is status posted -> cancelled,
 * performed together with creating a reversing entry (see the accounting engine, Phase 5).
 */
class JournalEntry extends Model
{
    protected $fillable = [
        'entry_no', 'entry_date', 'fiscal_year_id', 'voucher_type', 'reference', 'description',
        'source_type', 'source_id', 'reverses_entry_id', 'total_amount',
    ];

    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'posted_at' => 'datetime',
            'total_amount' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (JournalEntry $entry) {
            $illegal = array_diff(array_keys($entry->getDirty()), ['status', 'updated_at']);
            if ($illegal) {
                throw new DomainException('Posted journal entries cannot be edited. Cancel (reverse) instead.');
            }
        });

        static::deleting(function () {
            throw new DomainException('Journal entries cannot be deleted. Cancel (reverse) instead.');
        });
    }

    public function lines(): HasMany           { return $this->hasMany(JournalEntryLine::class)->orderBy('line_no'); }
    public function reverses(): BelongsTo      { return $this->belongsTo(self::class, 'reverses_entry_id'); }
    public function reversal()                 { return $this->hasOne(self::class, 'reverses_entry_id'); }
    public function fiscalYear(): BelongsTo    { return $this->belongsTo(FiscalYear::class); }
    public function creator(): BelongsTo       { return $this->belongsTo(User::class, 'created_by'); }

    public function totalDebit(): string
    {
        return number_format((float) $this->lines()->sum('debit'), 2, '.', '');
    }

    public function totalCredit(): string
    {
        return number_format((float) $this->lines()->sum('credit'), 2, '.', '');
    }

    public function isBalanced(): bool
    {
        return $this->totalDebit() === $this->totalCredit();
    }
}
