<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/** @mixin User */
class UserResource extends JsonResource
{
    private bool $withPermissions = false;

    /** Include the effective permission keys (used by /auth/me so the SPA can render the right UI). */
    public function withPermissions(bool $value = true): static
    {
        $this->withPermissions = $value;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'country_code' => $user->country_code,
            'locale' => $user->locale,
            'timezone' => $user->timezone,
            'type' => $user->type,
            'is_active' => $user->is_active,
            'avatar_url' => $user->avatar_path ? Storage::disk('public')->url($user->avatar_path) : null,
            'email_verified' => $user->email_verified_at !== null,
            'two_factor_enabled' => $user->hasTwoFactorEnabled(),
            'is_locked' => $user->isLocked(),
            'last_login_at' => $user->last_login_at,
            'created_at' => $user->created_at,
            'roles' => $this->whenLoaded('roles', fn () => $user->roles->map(fn ($role) => [
                'id' => $role->id,
                'slug' => $role->slug,
                'name_ar' => $role->name_ar,
                'name_en' => $role->name_en,
            ])->values()),
            'permissions' => $this->when($this->withPermissions, fn () => $user->permissionKeys()->sort()->values()),
        ];
    }
}
