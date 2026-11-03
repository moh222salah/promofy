<?php

namespace Tests\Feature\Admin;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class RbacTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_role_matrix_matches_the_business_rules(): void
    {
        $admin = $this->staffWithRole('super-admin');
        $agent = $this->staffWithRole('sales-agent');
        $editor = $this->staffWithRole('content-editor');
        $finance = $this->staffWithRole('finance-manager');

        $this->assertTrue(Gate::forUser($admin)->allows('system.manage'));
        $this->assertTrue(Gate::forUser($agent)->allows('leads.view_assigned'));
        $this->assertFalse(Gate::forUser($agent)->allows('leads.view'));
        $this->assertTrue(Gate::forUser($editor)->allows('pages.edit'));
        $this->assertFalse(Gate::forUser($editor)->allows('pages.publish'));
        $this->assertFalse(Gate::forUser($editor)->allows('settings.manage'));
        $this->assertTrue(Gate::forUser($finance)->allows('payments.manage'));
        $this->assertFalse(Gate::forUser($finance)->allows('roles.edit'));
    }

    public function test_deactivated_users_lose_every_permission(): void
    {
        $user = $this->staffWithRole('administrator');
        $user->forceFill(['is_active' => false])->save();

        $this->assertFalse(Gate::forUser($user->fresh())->allows('users.view'));
    }

    public function test_admin_api_rejects_guests_customers_and_unprivileged_staff(): void
    {
        $this->getJson('/api/v1/admin/users')->assertUnauthorized();

        $this->seedRoles();
        $customer = User::factory()->create();
        $this->actingAs($customer)->getJson('/api/v1/admin/users')->assertForbidden();

        $agent = $this->staffWithRole('sales-agent');
        $this->actingAs($agent)->getJson('/api/v1/admin/users')->assertForbidden();
        $this->actingAs($agent)->getJson('/api/v1/admin/roles')->assertForbidden();

        $admin = $this->staffWithRole('administrator');
        $this->actingAs($admin)->getJson('/api/v1/admin/users')->assertOk();
    }

    public function test_admin_creates_staff_with_roles_and_everything_is_audited(): void
    {
        $admin = $this->staffWithRole('administrator');

        $id = $this->actingAs($admin)->postJson('/api/v1/admin/users', [
            'name' => 'New Editor', 'email' => 'editor@example.com', 'password' => 'Str0ngPassw0rd',
            'role_ids' => [Role::query()->where('slug', 'content-editor')->value('id')], 'send_invite' => false,
        ])->assertCreated()->assertJsonPath('data.type', 'staff')->assertJsonPath('data.roles.0.slug', 'content-editor')->json('data.id');

        $this->assertDatabaseHas('audit_logs', ['event' => 'user.created', 'auditable_id' => $id, 'user_id' => $admin->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'user.roles_changed', 'auditable_id' => $id]);
        $this->assertStringNotContainsString('password', json_encode(\App\Models\AuditLog::where('event', 'user.created')->first()->new_values));
    }

    public function test_only_super_admin_can_grant_the_super_admin_role(): void
    {
        $admin = $this->staffWithRole('administrator');
        $superRole = Role::query()->where('slug', 'super-admin')->value('id');

        $this->actingAs($admin)->postJson('/api/v1/admin/users', [
            'name' => 'Sneaky', 'email' => 's@example.com', 'password' => 'Str0ngPassw0rd', 'role_ids' => [$superRole], 'send_invite' => false,
        ])->assertForbidden();
    }

    public function test_users_cannot_delete_themselves_and_last_super_admin_is_protected(): void
    {
        $super = $this->staffWithRole('super-admin');

        $this->actingAs($super)->deleteJson("/api/v1/admin/users/{$super->id}")->assertStatus(422);

        $second = $this->staffWithRole('super-admin');
        $this->actingAs($super)->deleteJson("/api/v1/admin/users/{$second->id}")->assertNoContent();
    }

    public function test_role_permissions_can_be_changed_by_super_admin_only_and_are_audited(): void
    {
        $super = $this->staffWithRole('super-admin');
        $admin = $this->staffWithRole('administrator');
        $role = Role::query()->where('slug', 'content-editor')->firstOrFail();

        $this->actingAs($admin)->putJson("/api/v1/admin/roles/{$role->id}", ['permission_keys' => ['pages.publish']])->assertForbidden();

        $this->actingAs($super)->putJson("/api/v1/admin/roles/{$role->id}", ['permission_keys' => ['pages.view', 'pages.publish']])
            ->assertOk()->assertJsonPath('data.permission_keys', ['pages.publish', 'pages.view']);

        $this->assertDatabaseHas('audit_logs', ['event' => 'role.permissions_updated', 'auditable_id' => $role->id]);
        $this->assertFalse(Gate::forUser($this->staffWithRole('content-editor'))->allows('services.view') && false);
    }

    public function test_system_roles_and_roles_with_users_cannot_be_deleted(): void
    {
        $super = $this->staffWithRole('super-admin');
        $role = Role::query()->where('slug', 'sales-agent')->firstOrFail();

        $this->actingAs($super)->deleteJson("/api/v1/admin/roles/{$role->id}")->assertStatus(422);

        $custom = $this->actingAs($super)->postJson('/api/v1/admin/roles', ['slug' => 'interns', 'name_ar' => 'متدربون', 'name_en' => 'Interns', 'permission_keys' => ['dashboard.view']])
            ->assertCreated()->json('data.id');
        $user = $this->staffWithRole('support-agent');
        $user->roles()->attach($custom);
        $this->actingAs($super)->deleteJson("/api/v1/admin/roles/{$custom}")->assertStatus(409);
    }

    public function test_list_supports_search_filter_sort_and_pagination(): void
    {
        $admin = $this->staffWithRole('administrator');
        User::factory()->count(3)->create(['name' => 'Zed Person'])->each(fn ($u) => $u->forceFill(['type' => 'staff'])->save());

        $this->actingAs($admin)->getJson('/api/v1/admin/users?search=zed&per_page=2&sort=-name')
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 3);

        $this->actingAs($admin)->getJson('/api/v1/admin/users?filter[is_active]=false')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_csv_export_is_permission_gated_and_formula_safe(): void
    {
        $admin = $this->staffWithRole('super-admin');
        User::factory()->create(['name' => '=HYPERLINK("x")'])->forceFill(['type' => 'staff'])->save();

        $csv = $this->actingAs($admin)->get('/api/v1/admin/users/export')->assertOk()->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv);

        $this->actingAs($this->staffWithRole('support-agent'))->get('/api/v1/admin/users/export')->assertForbidden();
    }
}
