<?php

namespace App\Http\Controllers\Api\Admin;

use App\Models\Permission;
use App\Models\Role;
use App\Support\Audit\AuditLogger;
use App\Support\Crud\CrudController;
use App\Support\Permissions\PermissionCatalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RoleController extends CrudController
{
    protected string $model = Role::class;

    protected string $module = 'roles';

    protected array $searchable = ['slug', 'name_ar', 'name_en'];

    protected array $sortable = ['id', 'slug', 'name_en', 'created_at'];

    protected string $defaultSort = 'id';

    protected array $with = ['permissions'];

    protected array $withCount = ['users'];

    protected function rules(?Model $record): array
    {
        $rules = [
            'slug' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('roles', 'slug')->ignore($record?->getKey())],
            'name_ar' => ['required', 'string', 'max:120'],
            'name_en' => ['required', 'string', 'max:120'],
            'description_ar' => ['nullable', 'string', 'max:255'],
            'description_en' => ['nullable', 'string', 'max:255'],
            'permission_keys' => ['sometimes', 'array'],
            'permission_keys.*' => ['string', Rule::in(PermissionCatalog::all())],
        ];

        if ($record?->is_system) {
            unset($rules['slug']); // slugs of system roles are fixed
        }

        return $rules;
    }

    protected function persistNew(array $data, Request $request): Model
    {
        $role = new Role;
        $role->fill(\Illuminate\Support\Arr::only($data, $role->getFillable()));
        $role->forceFill(['is_system' => false])->save();

        return $role;
    }

    protected function afterSave(Model $record, array $data, Request $request, bool $created): void
    {
        if (! array_key_exists('permission_keys', $data) || $record->isSuperAdmin()) {
            return; // the super admin role always holds every permission
        }

        $old = $record->permissions()->pluck('key')->all();
        $new = array_values(array_unique($data['permission_keys']));
        $added = array_values(array_diff($new, $old));
        $removed = array_values(array_diff($old, $new));

        $actor = $request->user();
        if (! $actor->isSuperAdmin() && array_diff($added, $actor->permissionKeys()->all()) !== []) {
            throw ValidationException::withMessages(['permission_keys' => [__('crud.cannot_grant_permission')]]);
        }

        $record->permissions()->sync(Permission::query()->whereIn('key', $new)->pluck('id')->all());

        if ($added || $removed) {
            AuditLogger::record('role.permissions_updated', $record, ['removed' => $removed], ['added' => $added]);
        }
    }

    protected function beforeDelete(Model $record, Request $request): void
    {
        abort_if($record->is_system, 422, __('crud.system_role_locked'));
        abort_if($record->users()->exists(), 409, __('crud.role_has_users'));
    }
}
