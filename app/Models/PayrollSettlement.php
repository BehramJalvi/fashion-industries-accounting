<?php

namespace App\Models;

use Database\Factories\PayrollSettlementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollSettlement extends Model
{
    /** @use HasFactory<PayrollSettlementFactory> */
    use HasFactory;

    protected $fillable = ['payroll_item_id', 'payment_id', 'idempotency_key', 'amount', 'created_by'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(PayrollItem::class, 'payroll_item_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
