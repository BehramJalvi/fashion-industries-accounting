<?php

namespace App\Models;

use App\Models\Concerns\HasLedgerBalance;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Owner extends Model
{
    use HasLedgerBalance, SoftDeletes;

    protected $fillable = [
        'name',
        'phone',
        'email',
        'share_percent',
        'notes',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'share_percent' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(OwnerTransaction::class);
    }

    /** Alias written to journal_entry_lines.party_type. */
    public function getMorphClass(): string
    {
        return 'owner';
    }

    protected function controlAccountCodes(): array
    {
        return ['3000', '3100'];
    }

    protected function balanceNature(): string
    {
        return 'credit';
    }
}
