<?php

namespace App\Models;

use App\Models\Concerns\IsPostable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class FundTransfer extends Model
{
    use SoftDeletes, IsPostable;

    protected $fillable = [
        'transfer_no',
        'transfer_date',
        'from_account_id',
        'to_account_id',
        'amount',
        'reference',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'transfer_date' => 'date',
            'amount' => 'decimal:2',
            'posted_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function fromAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'from_account_id');
    }

    public function toAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'to_account_id');
    }
}
