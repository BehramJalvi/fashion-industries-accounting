<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only stock ledger. Corrections are made with reversing rows, never by editing. */
class InventoryTransaction extends Model
{
    protected $fillable = [
        'product_id', 'txn_date', 'txn_type', 'source_type', 'source_id', 'qty_in', 'qty_out',
        'unit_cost', 'total_cost', 'balance_qty', 'avg_cost_after', 'balance_value', 'reverses_id',
    ];

    protected function casts(): array
    {
        return [
            'txn_date' => 'date',
            'qty_in' => 'decimal:3', 'qty_out' => 'decimal:3', 'balance_qty' => 'decimal:3',
            'unit_cost' => 'decimal:4', 'avg_cost_after' => 'decimal:4',
            'total_cost' => 'decimal:2', 'balance_value' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new DomainException('Inventory transactions are immutable.'));
        static::deleting(fn () => throw new DomainException('Inventory transactions cannot be deleted.'));
    }

    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
}
