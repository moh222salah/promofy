<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Resources\UserResource;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Crud\CrudController;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

/** Staff accounts. Storefront customer accounts are managed from the customers module. */
class UserController extends CrudController
{
    protected string $model = User::class;

    protected string $module = 'users';

    protected array $searchable = ['name', 'email', 'phone'];

    protected array $filterable = ['is_active', 'locale', 'country_code'];

    protected array $sortable = ['id', 'name', 'email', 'created_at', 'last_login_at'];

    protected array $with = ['roles'];

    protected string $resourceClass = UserResource::class;

    protected function baseQuery(string $scope): Builder
    {
        return parent::baseQuery($scope)->where('users.type', User::TYPE_STAFF);
    }

    protected function rules(?Model $record): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($record?->getKey())],
            'phone' => ['nullable', 'string', 'max:32'],
            'country_code' => ['nullable', 'string', 'size:2'],
            'locale' => ['sometimes', Rule::in(config('promofy.locales'))],
            'timezone' => ['sometimes', 'timezone'],
            'is_active' => ['sometimes', 'boolean'],
            'password' => ['nullable', 'string', PasswordRule::defaults()],
            'role_ids' => ['required', 'array', 'min:1'],
            'role_ids.*' => ['integer', 'exists:roles,id'],
            'send_invite' => ['sometimes', 'boolean'],
        ];
    }

    protected function persistNew(array $data, Request $request): Model
    {
        $user = new User;
        $user->fill(Arr::only($data, $user->getFillable()));
        $user->email = Str::lower($user->email);
        $user->forceFill([
            'type' => User::TYPE_STAFF,
            'is_active' => $data['is_active'] ?? true,
            'email_verified_at' => now(),
        ]);
        if (empty($data['password'])) {
            $user->password = Str::random(40); // unusable until the invitation link is used
        }
        $user->save();

        return $user;
    }

    protected function beforeUpdate(Model $record, array $data, Request $request): void
    {
        $this->guardSuperAdminTarget($record, $request);

        if (array_key_exists('is_active', $data) && ! $data['is_active']) {
            $this->guardSelfAndLastSuperAdmin($record, $request);
        }
    }

    protected function persistUpdate(Model $record, array $data, Request $request): void
    {
        $record->fill(Arr::only($data, $record->getFillable()));
        if (array_key_exists('is_active', $data)) {
            $record->forceFill(['is_active' => $data['is_active']]);
        }
        $record->save();
    }

    protected function afterSave(Model $record, array $data, Request $request, bool $created): void
    {
        if (isset($data['role_ids'])) {
            $this->syncRoles($record, $data['role_ids'], $request);
        }

        if ($created && ($data['send_invite'] ?? empty($data['password']))) {
            Password::sendResetLink(['email' => $record->email]);
        }
    }

    protected function beforeDelete(Model $record, Request $request): void
    {
        $this->guardSuperAdminTarget($record, $request);
        $this->guardSelfAndLastSuperAdmin($record, $request);
    }

    public function unlock(string $id): JsonResponse
    {
        $this->authorizeAction('manage');
        $user = $this->findRecord($id, 'all');
        $user->forceFill(['failed_login_attempts' => 0, 'locked_until' => null])->saveQuietly();
        AuditLogger::record('user.unlocked', $user);

        return $this->respond($this->reload($user));
    }

    public function resetTwoFactor(Request $request, string $id): JsonResponse
    {
        $this->authorizeAction('manage');
        $user = $this->findRecord($id, 'all');
        $this->guardSuperAdminTarget($user, $request);
        app(\App\Services\Auth\TwoFactorService::class)->disable($user, $request->user());

        return $this->respond($this->reload($user));
    }

    public function sendPasswordReset(string $id): JsonResponse
    {
        $this->authorizeAction('manage');
        $user = $this->findRecord($id, 'all');
        Password::sendResetLink(['email' => $user->email]);
        AuditLogger::record('user.password_reset_sent', $user);

        return response()->json(['message' => __('passwords.sent')]);
    }

    public function revokeSessions(Request $request, string $id)
    {
        $this->authorizeAction('manage');
        $user = $this->findRecord($id, 'all');
        $this->guardSuperAdminTarget($user, $request);
        DB::table('sessions')->where('user_id', $user->id)->delete();
        AuditLogger::record('user.sessions_revoked', $user);

        return response()->noContent();
    }

    public function loginHistory(string $id): JsonResponse
    {
        Gate::authorize('audit.view');
        $user = $this->findRecord($id, 'all');

        return response()->json(['data' => $user->hasMany(\App\Models\LoginHistory::class)->latest('id')->limit(100)->get()]);
    }

    /** @param  list<int>  $roleIds */
    private function syncRoles(Model $user, array $roleIds, Request $request): void
    {
        $actor = $request->user();
        $requested = Role::query()->whereIn('id', $roleIds)->get();
        $before = $user->roles()->pluck('slug')->sort()->values()->all();
        $touchesSuperAdmin = $requested->contains(fn (Role $r) => $r->isSuperAdmin()) || in_array('super-admin', $before, true);

        if ($touchesSuperAdmin && ! $actor->isSuperAdmin()) {
            abort(403, __('crud.cannot_assign_super_admin'));
        }

        $after = $requested->pluck('slug')->sort()->values()->all();
        if (in_array('super-admin', $before, true) && ! in_array('super-admin', $after, true) && $this->isLastSuperAdmin($user)) {
            throw ValidationException::withMessages(['role_ids' => [__('crud.last_super_admin')]]);
        }

        $user->roles()->sync($requested->pluck('id')->all());
        $user->forgetPermissionCache();

        if ($before !== $after) {
            AuditLogger::record('user.roles_changed', $user, ['roles' => $before], ['roles' => $after]);
        }
    }

    private function guardSuperAdminTarget(Model $target, Request $request): void
    {
        if ($target->isSuperAdmin() && ! $request->user()->isSuperAdmin()) {
            abort(403, __('crud.cannot_modify_super_admin'));
        }
    }

    private function guardSelfAndLastSuperAdmin(Model $target, Request $request): void
    {
        if ($target->getKey() === $request->user()->getKey()) {
            abort(422, __('crud.cannot_delete_self'));
        }
        if ($target->isSuperAdmin() && $this->isLastSuperAdmin($target)) {
            abort(422, __('crud.last_super_admin'));
        }
    }

    private function isLastSuperAdmin(Model $user): bool
    {
        return ! User::query()
            ->where('is_active', true)
            ->whereKeyNot($user->getKey())
            ->whereHas('roles', fn (Builder $q) => $q->where('slug', 'super-admin'))
            ->exists();
    }

    protected function exportColumns(): array
    {
        return ['ID' => 'id', 'Name' => 'name', 'Email' => 'email', 'Phone' => 'phone', 'Active' => 'is_active', 'Created' => 'created_at'];
    }
}
