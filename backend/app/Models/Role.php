<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Support\Permissions\PermissionCatalog;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['slug', 'name_ar', 'name_en', 'description_ar', 'description_en'])]
class Role extends Model
{
    use Auditable;

    protected $appends = ['permission_keys'];

    protected array $auditExclude = ['permission_keys'];

    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    public function isSuperAdmin(): bool
    {
        return $this->slug === PermissionCatalog::SUPER_ADMIN;
    }

    /** @return list<string> */
    public function getPermissionKeysAttribute(): array
    {
        if (! $this->relationLoaded('permissions')) {
            return [];
        }

        return $this->permissions->pluck('key')->sort()->values()->all();
    }
}
