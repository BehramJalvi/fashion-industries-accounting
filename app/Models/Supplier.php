<?php

namespace App\Models;

use App\Models\Concerns\HasLedgerBalance;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Supplier extends Model
{
    use HasLedgerBalance, SoftDeletes;

    protected $fillable = [
        'code',
        'name',
        'company',
        'contact_person',
        'phone',
        'email',
        'address',
        'ntn',
        'strn',
        'payment_terms_days',
        'notes',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class);
    }

    /** Alias written to journal_entry_lines.party_type. */
    public function getMorphClass(): string
    {
        return 'supplier';
    }

    protected function controlAccountCodes(): array
    {
        return ['2000'];
    }

    protected function balanceNature(): string
    {
        return 'credit';
    }
}
