<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseInvoicePrint extends Model
{
    protected $fillable = ['purchase_id', 'print_count', 'printed_by', 'printed_at'];

    protected function casts(): array
    {
        return ['printed_at' => 'datetime'];
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function printedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'printed_by');
    }
}
