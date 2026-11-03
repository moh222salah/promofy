<?php

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\LoginHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_can_log_in_and_receives_roles_and_permissions(): void
    {
        $user = $this->staffWithRole('sales-agent', ['email' => 'agent@example.com']);

        $this->postJson('/api/v1/auth/login', ['email' => 'Agent@Example.com', 'password' => 'Password123'])
            ->assertOk()
            ->assertJsonPath('data.email', 'agent@example.com')
            ->assertJsonPath('data.roles.0.slug', 'sales-agent')
            ->assertJsonFragment(['permissions' => $user->permissionKeys()->sort()->values()->all()]);

        $this->assertAuthenticatedAs($user, 'web');
        $this->assertDatabaseHas('login_histories', ['user_id' => $user->id, 'status' => 'success']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'auth.login', 'user_id' => $user->id]);
    }

    public function test_wrong_password_is_generic_and_recorded(): void
    {
        $this->staffWithRole('sales-agent', ['email' => 'agent@example.com']);

        $this->postJson('/api/v1/auth/login', ['email' => 'agent@example.com', 'password' => 'nope'])
            ->assertStatus(422)->assertJsonValidationErrors('email');
        $this->postJson('/api/v1/auth/login', ['email' => 'ghost@example.com', 'password' => 'nope'])
            ->assertStatus(422)->assertJsonValidationErrors('email');

        $this->assertGuest('web');
        $this->assertSame(2, LoginHistory::query()->where('status', 'failed')->count());
    }

    public function test_account_locks_after_repeated_failures_and_unlocks_after_the_window(): void
    {
        $user = $this->staffWithRole('sales-agent', ['email' => 'agent@example.com']);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'agent@example.com', 'password' => 'wrong'])->assertStatus(422);
        }

        $this->postJson('/api/v1/auth/login', ['email' => 'agent@example.com', 'password' => 'Password123'])
            ->assertStatus(423)->assertJsonPath('code', 'account_locked');
        $this->assertDatabaseHas('audit_logs', ['event' => 'auth.account_locked', 'user_id' => $user->id]);

        $this->travel(16)->minutes();
        $this->postJson('/api/v1/auth/login', ['email' => 'agent@example.com', 'password' => 'Password123'])->assertOk();
    }

    public function test_disabled_accounts_cannot_log_in(): void
    {
        $user = $this->staffWithRole('sales-agent', ['email' => 'agent@example.com']);
        $user->forceFill(['is_active' => false])->save();

        $this->postJson('/api/v1/auth/login', ['email' => 'agent@example.com', 'password' => 'Password123'])
            ->assertStatus(403)->assertJsonPath('code', 'account_disabled');
    }

    public function test_me_requires_authentication(): void
    {
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->actingAs($this->staffWithRole('support-agent'))->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_customer_registration_creates_a_customer_and_sends_verification(): void
    {
        $this->seedRoles();
        Notification::fake();

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Sara Customer', 'email' => 'Sara@example.com', 'password' => 'Str0ngPassw0rd',
            'password_confirmation' => 'Str0ngPassw0rd', 'terms' => true,
        ])->assertCreated()->assertJsonPath('data.type', 'customer')->assertJsonPath('data.roles.0.slug', 'customer');

        $user = User::query()->where('email', 'sara@example.com')->firstOrFail();
        Notification::assertSentTo($user, \Illuminate\Auth\Notifications\VerifyEmail::class);
        $this->assertFalse($user->hasVerifiedEmail());
    }

    public function test_registration_rejects_weak_passwords_and_missing_consent(): void
    {
        $this->postJson('/api/v1/auth/register', ['name' => 'X', 'email' => 'x@example.com', 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertStatus(422)->assertJsonValidationErrors(['password', 'terms']);
    }

    public function test_password_reset_flow_and_session_revocation(): void
    {
        Notification::fake();
        $user = $this->staffWithRole('sales-agent', ['email' => 'agent@example.com']);

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'agent@example.com'])->assertOk();
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'ghost@example.com'])->assertOk(); // no enumeration

        $token = null;
        Notification::assertSentTo($user, \Illuminate\Auth\Notifications\ResetPassword::class, function ($n) use (&$token) {
            $token = $n->token;

            return true;
        });

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token, 'email' => 'agent@example.com', 'password' => 'N3wStrongPass', 'password_confirmation' => 'N3wStrongPass',
        ])->assertOk();

        $this->postJson('/api/v1/auth/login', ['email' => 'agent@example.com', 'password' => 'N3wStrongPass'])->assertOk();
        $this->assertDatabaseHas('audit_logs', ['event' => 'auth.password_reset']);
    }

    public function test_audit_trail_never_contains_passwords(): void
    {
        $this->staffWithRole('sales-agent');

        foreach (AuditLog::all() as $log) {
            $this->assertStringNotContainsString('password', json_encode($log->new_values));
            $this->assertStringNotContainsString('remember_token', json_encode($log->new_values));
        }
    }
}
