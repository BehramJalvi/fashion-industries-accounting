<?php

namespace App\Models;

use App\Models\Concerns\IsPostable;
use App\Services\Sales\SaleSummary;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Sale extends Model
{
    use IsPostable, SoftDeletes;

    protected $fillable = [
        'invoice_no', 'customer_id', 'sale_date', 'due_date', 'gate_pass_no', 'subtotal', 'discount_total', 'tax_total', 'total', 'cogs_total',
        'received_now', 'received_into_account_id', 'buyer_name', 'buyer_address', 'buyer_ntn', 'buyer_strn', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'sale_date' => 'date', 'due_date' => 'date',
            'subtotal' => 'decimal:2', 'discount_total' => 'decimal:2', 'tax_total' => 'decimal:2', 'total' => 'decimal:2',
            'cogs_total' => 'decimal:2', 'received_now' => 'decimal:2',
            'posted_at' => 'datetime', 'cancelled_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo        { return $this->belongsTo(Customer::class); }
    public function receivedInto(): BelongsTo    { return $this->belongsTo(Account::class, 'received_into_account_id'); }
    public function items(): HasMany             { return $this->hasMany(SaleItem::class)->orderBy('id'); }   // posting pairs lines by position
    public function returns(): HasMany           { return $this->hasMany(SalesReturn::class); }
    public function invoices(): HasMany          { return $this->hasMany(Invoice::class); }

    /** Draft invoices carry a throw-away placeholder; the real number is assigned when posted. */
    public function hasInvoiceNumber(): bool     { return ! str_starts_with((string) $this->invoice_no, 'DRAFT-'); }

    /** @return array{paid:int, returned:int, refunded:int, balance:int, status:string}  paisa; derived, never stored */
    public function summary(): array             { return SaleSummary::for(collect([$this]))[$this->getKey()]; }

    public function paidAmount(): string         { return Money::fromCents($this->summary()['paid']); }
    public function balanceDue(): string         { return Money::fromCents($this->summary()['balance']); }
    /** draft | unpaid | partial | paid | cancelled */
    public function paymentStatus(): string      { return $this->summary()['status']; }

    public function grossProfit(): string        { return Money::fromCents(Money::toCents($this->subtotal) - Money::toCents($this->discount_total) - Money::toCents($this->cogs_total)); }
}
