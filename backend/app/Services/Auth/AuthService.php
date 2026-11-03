<?php

namespace App\Services\Auth;

use App\Models\LoginHistory;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Permissions\PermissionCatalog;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthService
{
    private static ?string $dummyHash = null;

    public function __construct(private readonly TwoFactorService $twoFactor) {}

    /**
     * @return array{two_factor: bool, user: User}
     */
    public function attempt(Request $request, string $email, string $password, bool $remember): array
    {
        $email = Str::lower(trim($email));
        $user = User::query()->where('email', $email)->first();

        if ($user?->isLocked()) {
            $seconds = max(1, $user->locked_until->getTimestamp() - now()->getTimestamp());
            $this->history($request, $user, $email, 'locked', 'account locked');
            $this->fail(423, 'account_locked', __('auth.locked', ['minutes' => (int) ceil($seconds / 60)]), ['retry_after' => $seconds]);
        }

        if ($user === null || ! Hash::check($password, $user->password)) {
            if ($user === null) {
                // Equalise timing so unknown emails are indistinguishable from wrong passwords.
                Hash::check($password, self::$dummyHash ??= Hash::make(Str::random(32)));
            } else {
                $this->registerFailure($user);
            }
            $this->history($request, $user, $email, 'failed', 'invalid credentials');

            throw ValidationException::withMessages(['email' => [__('auth.failed')]]);
        }

        if (! $user->is_active) {
            $this->history($request, $user, $email, 'disabled', 'account disabled');
            $this->fail(403, 'account_disabled', __('auth.disabled'));
        }

        $user->forceFill(['failed_login_attempts' => 0, 'locked_until' => null])->saveQuietly();

        if ($user->hasTwoFactorEnabled()) {
            $request->session()->put('two_factor', [
                'id' => $user->id,
                'remember' => $remember,
                'expires' => now()->addMinutes((int) config('promofy.security.two_factor_challenge_minutes', 5))->getTimestamp(),
            ]);

            return ['two_factor' => true, 'user' => $user];
        }

        $this->login($request, $user, $remember);

        return ['two_factor' => false, 'user' => $user];
    }

    public function completeTwoFactor(Request $request, ?string $code, ?string $recoveryCode): User
    {
        $pending = $request->session()->get('two_factor');
        $user = is_array($pending) && ($pending['expires'] ?? 0) >= now()->getTimestamp()
            ? User::query()->find($pending['id'])
            : null;

        if ($user === null) {
            $request->session()->forget('two_factor');
            $this->fail(422, 'two_factor_expired', __('auth.two_factor_expired'));
        }

        if (! $this->twoFactor->verifyChallenge($user, $code, $recoveryCode)) {
            $this->history($request, $user, $user->email, 'two_factor_failed', 'invalid code');
            throw ValidationException::withMessages(['code' => [__('auth.two_factor_invalid')]]);
        }

        $request->session()->forget('two_factor');
        $this->login($request, $user, (bool) ($pending['remember'] ?? false));

        return $user;
    }

    public function login(Request $request, User $user, bool $remember = false): void
    {
        Auth::guard('web')->login($user, $remember);
        $request->session()->regenerate();

        $user->forceFill(['last_login_at' => now(), 'last_login_ip' => $request->ip()])->saveQuietly();
        $this->history($request, $user, $user->email, 'success');
        AuditLogger::record('auth.login', $user, userId: $user->id);
    }

    public function logout(Request $request): void
    {
        $user = $request->user();
        if ($user) {
            $this->history($request, $user, $user->email, 'logout');
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    /** @param  array<string, mixed>  $data */
    public function registerCustomer(array $data, Request $request): User
    {
        $user = DB::transaction(function () use ($data) {
            $user = new User;
            $user->fill([
                'name' => $data['name'],
                'email' => Str::lower($data['email']),
                'password' => $data['password'],
                'phone' => $data['phone'] ?? null,
                'country_code' => isset($data['country_code']) ? strtoupper($data['country_code']) : null,
                'locale' => $data['locale'] ?? config('promofy.default_locale'),
            ]);
            $user->forceFill(['type' => User::TYPE_CUSTOMER, 'is_active' => true])->save();

            $role = Role::query()->where('slug', PermissionCatalog::CUSTOMER)->first();
            if ($role) {
                $user->roles()->attach($role->id);
            }

            return $user;
        });

        event(new Registered($user)); // sends the verification email
        $this->login($request, $user);

        return $user;
    }

    public function history(Request $request, ?User $user, ?string $email, string $status, ?string $reason = null): void
    {
        LoginHistory::create([
            'user_id' => $user?->id,
            'email' => $email,
            'status' => $status,
            'reason' => $reason,
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
        ]);
    }

    private function registerFailure(User $user): void
    {
        $attempts = $user->failed_login_attempts + 1;

        if ($attempts >= (int) config('promofy.security.max_login_attempts', 5)) {
            $user->forceFill([
                'failed_login_attempts' => 0,
                'locked_until' => now()->addMinutes((int) config('promofy.security.lockout_minutes', 15)),
            ])->saveQuietly();
            AuditLogger::record('auth.account_locked', $user, metadata: ['attempts' => $attempts], userId: $user->id);

            return;
        }

        $user->forceFill(['failed_login_attempts' => $attempts])->saveQuietly();
    }

    /** @param  array<string, mixed>  $extra */
    private function fail(int $status, string $code, string $message, array $extra = []): never
    {
        throw new HttpResponseException(response()->json(['message' => $message, 'code' => $code] + $extra, $status));
    }
}
