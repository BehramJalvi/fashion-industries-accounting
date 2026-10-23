<?php

namespace App\Models;

use App\Models\Concerns\IsPostable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SalesReturn extends Model
{
    use IsPostable, SoftDeletes;

    protected $fillable = [
        'return_no', 'sale_id', 'customer_id', 'return_date', 'subtotal', 'tax_total', 'total',
        'refund_now', 'refund_account_id', 'reason', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'return_date' => 'date', 'subtotal' => 'decimal:2', 'tax_total' => 'decimal:2', 'total' => 'decimal:2', 'refund_now' => 'decimal:2',
            'posted_at' => 'datetime', 'cancelled_at' => 'datetime',
        ];
    }

    public function sale(): BelongsTo           { return $this->belongsTo(Sale::class); }
    public function customer(): BelongsTo       { return $this->belongsTo(Customer::class); }
    public function refundAccount(): BelongsTo  { return $this->belongsTo(Account::class, 'refund_account_id'); }
    public function items(): HasMany            { return $this->hasMany(SalesReturnItem::class); }
}
