<?php

namespace Tests;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Requests come from the first-party SPA origin, so Sanctum treats them as stateful.
        $this->withHeaders(['Origin' => 'http://localhost', 'Referer' => 'http://localhost/']);
    }

    protected function seedRoles(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /** Creates a staff user holding the given default role slug(s). */
    protected function staffWithRole(string|array $roles, array $attributes = []): User
    {
        $this->seedRoles();

        $user = User::factory()->create($attributes);
        $user->forceFill(['type' => User::TYPE_STAFF])->save();
        $user->roles()->attach(Role::query()->whereIn('slug', (array) $roles)->pluck('id'));

        return $user->fresh();
    }
}
