<?php

namespace App\Models\Concerns;

use App\Models\JournalEntry;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Shared behaviour for every accounting document (sale, purchase, receipt, ...).
 *
 * Lifecycle: draft -> posted -> cancelled. Nothing is ever hard-deleted once posted.
 * status / journal_entry_id / cancel_* are NOT mass-assignable; services set them with forceFill().
 */
trait IsPostable
{
    /** Columns that may still change on a posted document (only the cancel/reversal bookkeeping). */
    protected static array $postedMutable = [
        'status', 'reversal_entry_id', 'cancelled_at', 'cancelled_by', 'cancel_reason', 'updated_by', 'updated_at',
    ];

    public static function bootIsPostable(): void
    {
        static::updating(function ($model) {
            $original = $model->getOriginal('status');

            if ($original === 'cancelled') {
                throw new DomainException('Cancelled transactions cannot be edited.');
            }

            if ($original === 'posted') {
                $illegal = array_diff(array_keys($model->getDirty()), static::$postedMutable);
                if ($illegal) {
                    throw new DomainException(
                        'Posted transactions cannot be edited. Cancel and re-enter instead. (' . implode(', ', $illegal) . ')'
                    );
                }
            }
        });

        static::deleting(function ($model) {
            if ($model->status !== 'draft') {
                throw new DomainException('Posted or cancelled transactions cannot be deleted. Use Cancel instead.');
            }
        });
    }

    public function isDraft(): bool     { return $this->status === 'draft'; }
    public function isPosted(): bool    { return $this->status === 'posted'; }
    public function isCancelled(): bool { return $this->status === 'cancelled'; }

    public function scopePosted(Builder $q): Builder   { return $q->where($q->getModel()->getTable() . '.status', 'posted'); }
    public function scopeCancelled(Builder $q): Builder { return $q->where($q->getModel()->getTable() . '.status', 'cancelled'); }

    public function journalEntry(): BelongsTo   { return $this->belongsTo(JournalEntry::class, 'journal_entry_id'); }
    public function reversalEntry(): BelongsTo  { return $this->belongsTo(JournalEntry::class, 'reversal_entry_id'); }
    public function creator(): BelongsTo        { return $this->belongsTo(User::class, 'created_by'); }
    public function canceller(): BelongsTo      { return $this->belongsTo(User::class, 'cancelled_by'); }
}
