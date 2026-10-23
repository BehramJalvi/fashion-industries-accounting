<?php

namespace App\Models;

use App\Models\Concerns\IsPostable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Expense extends Model
{
    use SoftDeletes, IsPostable;

    protected $fillable = [
        'expense_no',
        'expense_date',
        'expense_account_id',
        'paid_from_account_id',
        'payee_type',
        'payee_id',
        'amount',
        'tax_amount',
        'description',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'expense_date' => 'date',
            'amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'posted_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function expenseAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'expense_account_id');
    }

    public function paidFromAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'paid_from_account_id');
    }


    /** Supplier or Employee, depending on payee_type. */
    public function payee(): ?Model
    {
        return match ($this->payee_type) {
            'supplier' => Supplier::find($this->payee_id),
            'employee' => Employee::find($this->payee_id),
            default => null,
        };
    }
}
