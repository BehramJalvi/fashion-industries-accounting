<?php

namespace App\Models;

use App\Models\Concerns\HasLedgerBalance;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
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
        'cnic',
        'credit_limit',
        'payment_terms_days',
        'tax_rate',
        'is_tax_exempt',
        'notes',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'credit_limit' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'is_tax_exempt' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(Receipt::class);
    }

    /** Alias written to journal_entry_lines.party_type. */
    public function getMorphClass(): string
    {
        return 'customer';
    }

    protected function controlAccountCodes(): array
    {
        return ['1100'];
    }

    protected function balanceNature(): string
    {
        return 'debit';
    }

    /** Tax rate to default onto a new invoice line. */
    public function effectiveTaxRate(): string
    {
        return $this->is_tax_exempt ? '0.00' : (string) $this->tax_rate;
    }
}
