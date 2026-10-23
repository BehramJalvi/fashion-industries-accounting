<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id', 'event', 'auditable_type', 'auditable_id', 'old_values', 'new_values', 'ip_address', 'user_agent',
    ];

    protected function casts(): array
    {
        return ['old_values' => 'array', 'new_values' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new DomainException('Audit log entries are immutable.'));
        static::deleting(fn () => throw new DomainException('Audit log entries cannot be deleted.'));
    }

    public function user(): BelongsTo      { return $this->belongsTo(User::class); }
    public function auditable(): MorphTo   { return $this->morphTo(); }
}
