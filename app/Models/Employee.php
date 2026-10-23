<?php

namespace App\Models;

use App\Models\Concerns\HasLedgerBalance;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employee extends Model
{
    use HasLedgerBalance, SoftDeletes;

    protected $fillable = [
        'code',
        'name',
        'designation',
        'phone',
        'monthly_salary',
        'bank_account_no',
        'notes',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'monthly_salary' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /** Alias written to journal_entry_lines.party_type. */
    public function getMorphClass(): string
    {
        return 'employee';
    }

    protected function controlAccountCodes(): array
    {
        return ['2100', '1460'];
    }

    protected function balanceNature(): string
    {
        return 'credit';
    }
}
