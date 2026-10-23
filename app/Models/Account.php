<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};

class Account extends Model
{
    protected $fillable = [
        'code',
        'name',
        'type',
        'sub_type',
        'normal_balance',
        'parent_id',
        'is_control',
        'party_type',
        'is_system',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_control' => 'boolean',
            'is_system' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Account::class, 'parent_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class);
    }

    protected static function booted(): void
    {
        static::deleting(function (Account $account) {
            if ($account->is_system || $account->lines()->exists()) {
                throw new DomainException('System accounts and accounts with postings cannot be deleted. Deactivate instead.');
            }
        });
    }

    public static function byCode(string $code): self
    {
        return static::where('code', $code)->firstOrFail();
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    public function isDebitNormal(): bool
    {
        return $this->normal_balance === 'debit';
    }
}
