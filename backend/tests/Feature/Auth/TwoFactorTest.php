<?php

namespace Tests\Feature\Auth;

use App\Support\Auth\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_two_factor_lifecycle(): void
    {
        $user = $this->staffWithRole('administrator', ['email' => 'admin@example.com']);

        // Enrolment
        $secret = $this->actingAs($user)->postJson('/api/v1/auth/two-factor/enable', ['password' => 'Password123'])
            ->assertOk()->json('data.secret');
        $this->assertFalse($user->fresh()->hasTwoFactorEnabled());

        $this->postJson('/api/v1/auth/two-factor/confirm', ['code' => '000000'])->assertStatus(422);
        $codes = $this->postJson('/api/v1/auth/two-factor/confirm', ['code' => Totp::codeAt($secret, Totp::stepFor())])
            ->assertOk()->json('data.recovery_codes');
        $this->assertCount(8, $codes);
        $this->assertTrue($user->fresh()->hasTwoFactorEnabled());

        // Login now requires the challenge
        $this->app['auth']->guard('web')->logout();
        $this->postJson('/api/v1/auth/login', ['email' => 'admin@example.com', 'password' => 'Password123'])
            ->assertOk()->assertJsonPath('two_factor_required', true);
        $this->assertGuest('web');

        $this->postJson('/api/v1/auth/two-factor-challenge', ['code' => '123456'])->assertStatus(422);
        $this->assertDatabaseHas('login_histories', ['status' => 'two_factor_failed']);

        // A recovery code works exactly once
        $this->postJson('/api/v1/auth/two-factor-challenge', ['recovery_code' => $codes[0]])->assertOk();
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertFalse($this->app['auth']->guard('web')->check() === false);

        $this->app['auth']->guard('web')->logout();
        $this->postJson('/api/v1/auth/login', ['email' => 'admin@example.com', 'password' => 'Password123'])->assertOk();
        $this->postJson('/api/v1/auth/two-factor-challenge', ['recovery_code' => $codes[0]])->assertStatus(422);
    }

    public function test_totp_codes_cannot_be_replayed(): void
    {
        $user = $this->staffWithRole('administrator', ['email' => 'admin@example.com']);
        $secret = $this->actingAs($user)->postJson('/api/v1/auth/two-factor/enable', ['password' => 'Password123'])->json('data.secret');
        $code = Totp::codeAt($secret, Totp::stepFor());
        $this->postJson('/api/v1/auth/two-factor/confirm', ['code' => $code])->assertOk();

        $this->app['auth']->guard('web')->logout();
        $this->postJson('/api/v1/auth/login', ['email' => 'admin@example.com', 'password' => 'Password123'])->assertOk();
        // The enrolment code (same time step) is already consumed.
        $this->postJson('/api/v1/auth/two-factor-challenge', ['code' => $code])->assertStatus(422);
    }

    public function test_enabling_requires_the_current_password(): void
    {
        $user = $this->staffWithRole('administrator');
        $this->actingAs($user)->postJson('/api/v1/auth/two-factor/enable', ['password' => 'wrong'])->assertStatus(422);
    }
}
