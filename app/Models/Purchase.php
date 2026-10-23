<?php

namespace App\Models;

use App\Models\Concerns\IsPostable;
use App\Services\Purchasing\PurchaseSummary;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Purchase extends Model
{
    use IsPostable, SoftDeletes;

    protected $fillable = [
        'purchase_no', 'supplier_id', 'funded_by_employee_id', 'purchase_date', 'due_date', 'supplier_ref',
        'subtotal', 'discount_total', 'tax_total', 'total', 'paid_now', 'paid_from_account_id', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'purchase_date' => 'date', 'due_date' => 'date',
            'subtotal' => 'decimal:2', 'discount_total' => 'decimal:2', 'tax_total' => 'decimal:2', 'total' => 'decimal:2', 'paid_now' => 'decimal:2',
            'posted_at' => 'datetime', 'cancelled_at' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function fundedByEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'funded_by_employee_id');
    }

    public function paidFromAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'paid_from_account_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseItem::class);
    }

    public function returns(): HasMany
    {
        return $this->hasMany(PurchaseReturn::class);
    }

    public function invoicePrint(): HasOne
    {
        return $this->hasOne(PurchaseInvoicePrint::class);
    }

    /** @return array{paid:int, returned:int, refunded:int, balance:int, status:string}  paisa; derived, never stored */
    public function summary(): array
    {
        return PurchaseSummary::for(collect([$this]))[$this->getKey()];
    }

    /** Money paid so far: paid at purchase time + payments allocated to this purchase. */
    public function paidAmount(): string
    {
        return Money::fromCents($this->summary()['paid']);
    }

    /** What is still owed to the supplier (or employee) for this purchase, after returns. Negative = overpaid. */
    public function balanceDue(): string
    {
        return Money::fromCents($this->summary()['balance']);
    }

    /** draft | unpaid | partial | paid | cancelled */
    public function paymentStatus(): string
    {
        return $this->summary()['status'];
    }
}
