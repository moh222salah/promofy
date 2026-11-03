<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\Country;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\LoginHistory;
use App\Models\Media;
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
        'media' => Media::class,
        'country' => Country::class,
        'currency' => Currency::class,
        'exchange_rate' => ExchangeRate::class,
    ];

    /** @return array<string, class-string> */
    public static function all(): array
    {
        return self::MAP;
    }
}
