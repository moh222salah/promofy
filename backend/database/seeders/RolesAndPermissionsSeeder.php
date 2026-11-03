<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Support\Permissions\PermissionCatalog;
use Illuminate\Database\Seeder;

/**
 * Idempotent: creates any missing permissions and system roles from the catalogue.
 * Existing role permissions are left untouched (administrators may have customised them);
 * only the super admin role is always re-synced to hold everything.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        foreach (PermissionCatalog::MODULES as $module => $actions) {
            foreach ($actions as $action) {
                Permission::query()->firstOrCreate(
                    ['key' => "{$module}.{$action}"],
                    ['module' => $module, 'action' => $action],
                );
            }
        }

        $ids = Permission::query()->pluck('id', 'key');

        foreach (PermissionCatalog::roles() as $slug => $definition) {
            $role = Role::query()->where('slug', $slug)->first();
            $created = $role === null;

            if ($created) {
                $role = new Role;
                $role->forceFill([
                    'slug' => $slug,
                    'name_ar' => $definition['name_ar'],
                    'name_en' => $definition['name_en'],
                    'description_en' => $definition['description_en'],
                    'is_system' => true,
                ])->save();
            }

            if ($created || $slug === PermissionCatalog::SUPER_ADMIN) {
                $keys = PermissionCatalog::expand($definition['permissions']);
                $role->permissions()->sync($ids->only($keys)->values()->all());
            }
        }
    }
}
