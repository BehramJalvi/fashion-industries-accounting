<?php

namespace App\Models;

use App\Models\Concerns\IsPostable;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Receipt extends Model
{
    use SoftDeletes, IsPostable;

    protected $fillable = [
        'receipt_no',
        'customer_id',
        'receipt_date',
        'method',
        'account_id',
        'amount',
        'reference',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'receipt_date' => 'date',
            'amount' => 'decimal:2',
            'posted_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class, 'document_id')->where('document_type', 'receipt');
    }

    /** Part of this document applied to specific invoices (active allocations only), in paisa. */
    public function allocatedCents(): int
    {
        return Money::toCents(PaymentAllocation::where('document_type', 'receipt')->where('document_id', $this->getKey())->where('is_active', true)->sum('amount'));
    }

    /** The rest stays on the party's account (advance / credit). Derived, never stored. */
    public function unallocatedCents(): int
    {
        return Money::toCents($this->amount) - $this->allocatedCents();
    }
}
