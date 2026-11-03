<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Support\Permissions\PermissionCatalog;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'phone', 'country_code', 'locale', 'timezone'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_last_step'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use Auditable, HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    public const TYPE_STAFF = 'staff';

    public const TYPE_CUSTOMER = 'customer';

    /** Attributes that change constantly or are secret and must never reach the audit trail. */
    protected array $auditExclude = ['failed_login_attempts', 'locked_until', 'last_login_at', 'last_login_ip', 'email_verified_at'];

    /** @var Collection<int, string>|null */
    private ?Collection $permissionCache = null;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'locked_until' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    public function isStaff(): bool
    {
        return $this->type === self::TYPE_STAFF;
    }

    public function hasRole(string|array $slugs): bool
    {
        $slugs = (array) $slugs;

        return $this->roles()->whereIn('slug', $slugs)->exists();
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole(PermissionCatalog::SUPER_ADMIN);
    }

    /** @return Collection<int, string> */
    public function permissionKeys(): Collection
    {
        if ($this->permissionCache !== null) {
            return $this->permissionCache;
        }

        if ($this->isSuperAdmin()) {
            return $this->permissionCache = collect(PermissionCatalog::all());
        }

        return $this->permissionCache = DB::table('permissions')
            ->join('permission_role', 'permission_role.permission_id', '=', 'permissions.id')
            ->join('role_user', 'role_user.role_id', '=', 'permission_role.role_id')
            ->where('role_user.user_id', $this->getKey())
            ->distinct()
            ->pluck('permissions.key');
    }

    public function hasPermission(string $key): bool
    {
        return $this->is_active && $this->permissionKeys()->contains($key);
    }

    public function forgetPermissionCache(): void
    {
        $this->permissionCache = null;
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_confirmed_at !== null && $this->two_factor_secret !== null;
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }
}
