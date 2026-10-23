<?php

namespace App\Models;

use App\Models\Concerns\IsPostable;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Payment extends Model
{
    use SoftDeletes, IsPostable;

    protected $fillable = [
        'payment_no',
        'payee_type',
        'payee_id',
        'payment_date',
        'method',
        'account_id',
        'amount',
        'reference',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'payment_date' => 'date',
            'amount' => 'decimal:2',
            'posted_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
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

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class, 'document_id')->where('document_type', 'payment');
    }

    /** Part of this document applied to specific bills (active allocations only), in paisa. */
    public function allocatedCents(): int
    {
        return Money::toCents(PaymentAllocation::where('document_type', 'payment')->where('document_id', $this->getKey())->where('is_active', true)->sum('amount'));
    }

    /** The rest stays on the party's account (advance / credit). Derived, never stored. */
    public function unallocatedCents(): int
    {
        return Money::toCents($this->amount) - $this->allocatedCents();
    }
}
