<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class AuditLogger
{
    /** Write one audit row. Never include passwords or tokens in $old/$new. */
    public static function log(string $event, ?Model $subject = null, ?array $old = null, ?array $new = null): void
    {
        $request = app()->bound('request') ? request() : null;
        $agent = $request?->userAgent();

        AuditLog::create([
            'user_id'        => Auth::id(),
            'event'          => $event,
            'auditable_type' => $subject?->getMorphClass(),
            'auditable_id'   => $subject?->getKey(),
            'old_values'     => $old ?: null,
            'new_values'     => $new ?: null,
            'ip_address'     => $request?->ip(),
            'user_agent'     => $agent ? Str::limit($agent, 250, '') : null,
        ]);
    }
}
