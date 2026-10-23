<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class Product extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'code', 'name', 'category_id', 'unit_id', 'product_type', 'purchase_price', 'selling_price',
        'default_tax_rate', 'min_stock', 'description', 'image_path', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'purchase_price' => 'decimal:4', 'selling_price' => 'decimal:4',
            'default_tax_rate' => 'decimal:2', 'min_stock' => 'decimal:3', 'is_active' => 'boolean',
        ];
    }

    public function category(): BelongsTo { return $this->belongsTo(Category::class); }
    public function unit(): BelongsTo     { return $this->belongsTo(Unit::class); }
    public function inventoryTransactions(): HasMany { return $this->hasMany(InventoryTransaction::class); }

    /** Latest ledger row (running balance, average cost and value live on it). */
    public function lastInventoryRow(): ?InventoryTransaction
    {
        return $this->inventoryTransactions()->orderByDesc('txn_date')->orderByDesc('id')->first();
    }

    // Stock is DERIVED from the inventory ledger; there is no editable stock column.
    public function currentStock(): string
    {
        return number_format((float) $this->inventoryTransactions()->selectRaw('COALESCE(SUM(qty_in - qty_out), 0) as q')->value('q'), 3, '.', '');
    }

    public function averageCost(): string
    {
        return $this->lastInventoryRow()?->avg_cost_after ?? '0.0000';
    }

    public function stockValue(): string
    {
        return $this->lastInventoryRow()?->balance_value ?? '0.00';
    }

    public function isLowStock(): bool
    {
        return (float) $this->currentStock() < (float) $this->min_stock;
    }

    /** Public URL of the uploaded image (needs `php artisan storage:link`), or null. */
    protected function imageUrl(): Attribute
    {
        return Attribute::get(fn () => $this->image_path ? Storage::disk('public')->url($this->image_path) : null);
    }
}
