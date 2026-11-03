<?php

namespace App\Support\Audit;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Writes entries to the append-only audit trail.
 *
 * Use it for every sensitive or administrative operation that is not already captured by the
 * Auditable model trait (logins, payment confirmations, refunds, permission changes, exports…).
 */
final class AuditLogger
{
    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @param  array<string, mixed>  $metadata
     */
    public static function record(
        string $event,
        ?Model $subject = null,
        array $old = [],
        array $new = [],
        array $metadata = [],
        ?int $userId = null,
    ): AuditLog {
        $request = app()->bound('request') ? app('request') : null;

        return AuditLog::create([
            'user_id' => $userId ?? Auth::id(),
            'event' => $event,
            'auditable_type' => $subject?->getMorphClass(),
            'auditable_id' => $subject?->getKey(),
            'old_values' => $old ?: null,
            'new_values' => $new ?: null,
            'metadata' => $metadata ?: null,
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 255) : null,
        ]);
    }
}
