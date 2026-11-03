<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\Totp;
use Illuminate\Validation\ValidationException;

class TwoFactorService
{
    /** Starts setup: stores an unconfirmed secret and returns it for the authenticator app. */
    public function enable(User $user): array
    {
        $secret = Totp::generateSecret();
        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_last_step' => null,
        ])->saveQuietly();

        return [
            'secret' => $secret,
            'otpauth_uri' => Totp::uri($secret, $user->email, config('promofy.brand.name')),
        ];
    }

    /** @return list<string> plain recovery codes, shown to the user once */
    public function confirm(User $user, string $code): array
    {
        if ($user->two_factor_secret === null || $user->two_factor_confirmed_at !== null) {
            throw ValidationException::withMessages(['code' => [__('auth.two_factor_not_pending')]]);
        }

        $step = Totp::verify($user->two_factor_secret, $code);
        if ($step === null) {
            throw ValidationException::withMessages(['code' => [__('auth.two_factor_invalid')]]);
        }

        $codes = $this->newRecoveryCodes();
        $user->forceFill([
            'two_factor_confirmed_at' => now(),
            'two_factor_last_step' => $step,
            'two_factor_recovery_codes' => array_map(fn (string $c) => $this->hash($c), $codes),
        ])->save();

        AuditLogger::record('auth.two_factor_enabled', $user, userId: $user->id);

        return $codes;
    }

    public function disable(User $user, ?User $actor = null): void
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_step' => null,
        ])->save();

        AuditLogger::record('auth.two_factor_disabled', $user, metadata: ['by' => $actor?->id], userId: $actor?->id ?? $user->id);
    }

    /** @return list<string> */
    public function regenerateRecoveryCodes(User $user): array
    {
        $codes = $this->newRecoveryCodes();
        $user->forceFill(['two_factor_recovery_codes' => array_map(fn (string $c) => $this->hash($c), $codes)])->save();
        AuditLogger::record('auth.recovery_codes_regenerated', $user, userId: $user->id);

        return $codes;
    }

    /** Verifies a TOTP code (replay-protected) or consumes a recovery code. */
    public function verifyChallenge(User $user, ?string $code, ?string $recoveryCode): bool
    {
        if (! $user->hasTwoFactorEnabled()) {
            return false;
        }

        if ($code !== null && $code !== '') {
            $step = Totp::verify($user->two_factor_secret, $code);
            if ($step === null || ($user->two_factor_last_step !== null && $step <= $user->two_factor_last_step)) {
                return false;
            }
            $user->forceFill(['two_factor_last_step' => $step])->saveQuietly();

            return true;
        }

        if ($recoveryCode !== null && $recoveryCode !== '') {
            $hash = $this->hash($recoveryCode);
            $codes = $user->two_factor_recovery_codes ?? [];
            foreach ($codes as $i => $stored) {
                if (hash_equals($stored, $hash)) {
                    unset($codes[$i]);
                    $user->forceFill(['two_factor_recovery_codes' => array_values($codes)])->saveQuietly();
                    AuditLogger::record('auth.recovery_code_used', $user, metadata: ['remaining' => count($codes)], userId: $user->id);

                    return true;
                }
            }
        }

        return false;
    }

    /** @return list<string> */
    private function newRecoveryCodes(): array
    {
        return array_map(function () {
            $raw = substr(Totp::base32Encode(random_bytes(7)), 0, 10);

            return substr($raw, 0, 5).'-'.substr($raw, 5, 5);
        }, range(1, 8));
    }

    private function hash(string $code): string
    {
        return hash_hmac('sha256', strtoupper(trim($code)), (string) config('app.key'));
    }
}
