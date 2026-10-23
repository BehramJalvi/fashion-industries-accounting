<?php

namespace App\Models;

use App\Models\Concerns\IsPostable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class OwnerTransaction extends Model
{
    use SoftDeletes, IsPostable;

    protected $fillable = [
        'txn_no',
        'owner_id',
        'txn_type',
        'txn_date',
        'account_id',
        'amount',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'txn_date' => 'date',
            'amount' => 'decimal:2',
            'posted_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
