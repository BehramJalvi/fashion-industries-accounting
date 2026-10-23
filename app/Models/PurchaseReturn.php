<?php

namespace App\Models;

use App\Models\Concerns\IsPostable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseReturn extends Model
{
    use IsPostable, SoftDeletes;

    protected $fillable = [
        'return_no', 'purchase_id', 'supplier_id', 'return_date', 'subtotal', 'tax_total', 'total',
        'refund_now', 'refund_account_id', 'reason', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'return_date' => 'date', 'subtotal' => 'decimal:2', 'tax_total' => 'decimal:2', 'total' => 'decimal:2', 'refund_now' => 'decimal:2',
            'posted_at' => 'datetime', 'cancelled_at' => 'datetime',
        ];
    }

    public function purchase(): BelongsTo       { return $this->belongsTo(Purchase::class); }
    public function supplier(): BelongsTo       { return $this->belongsTo(Supplier::class); }
    public function refundAccount(): BelongsTo  { return $this->belongsTo(Account::class, 'refund_account_id'); }
    public function items(): HasMany            { return $this->hasMany(PurchaseReturnItem::class); }
}
