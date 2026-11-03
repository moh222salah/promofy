<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\LoginHistory;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;

/**
 * Stable, readable type names used in polymorphic columns (audit log subjects, media usage…).
 * Every model that appears in a polymorphic relation must be registered here.
 */
final class MorphMap
{
    /** @var array<string, class-string> */
    public const MAP = [
        'user' => User::class,
        'role' => Role::class,
        'permission' => Permission::class,
        'audit_log' => AuditLog::class,
        'login_history' => LoginHistory::class,
    ];

    /** @return array<string, class-string> */
    public static function all(): array
    {
        return self::MAP;
    }
}
