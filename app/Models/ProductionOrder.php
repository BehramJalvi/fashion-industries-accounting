<?php

namespace App\Models;

use App\Models\Concerns\IsPostable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductionOrder extends Model
{
    use IsPostable, SoftDeletes;

    protected $fillable = [
        'order_no',
        'order_date',
        'customer_id',
        'processor_supplier_id',
        'processing_fee',
        'paid_from_account_id',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'order_date' => 'date',
            'processing_fee' => 'decimal:2',
            'posted_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function processor(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'processor_supplier_id');
    }

    public function paidFromAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'paid_from_account_id');
    }

    public function inputs(): HasMany
    {
        return $this->hasMany(ProductionInput::class);
    }

    public function outputs(): HasMany
    {
        return $this->hasMany(ProductionOutput::class);
    }
}
