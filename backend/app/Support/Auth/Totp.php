<?php

namespace App\Support\Auth;

/**
 * RFC 6238 time-based one-time passwords (HMAC-SHA1), compatible with Google Authenticator,
 * Microsoft Authenticator, 1Password, Authy and similar apps.
 */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    public static function base32Encode(string $binary): string
    {
        $bits = '';
        foreach (str_split($binary) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }

        return $out;
    }

    public static function base32Decode(string $encoded): string
    {
        $encoded = strtoupper(rtrim(preg_replace('/[\s-]+/', '', $encoded) ?? '', '='));

        $bits = '';
        foreach (str_split($encoded) as $char) {
            $value = strpos(self::ALPHABET, $char);
            if ($value === false) {
                return '';
            }
            $bits .= str_pad(decbin($value), 5, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }

        return $out;
    }

    /** The one-time code for a given 30-second time step. */
    public static function codeAt(string $secret, int $step, int $digits = 6): string
    {
        $key = self::base32Decode($secret);
        $counter = pack('N2', ($step >> 32) & 0xFFFFFFFF, $step & 0xFFFFFFFF);
        $hash = hash_hmac('sha1', $counter, $key, true);

        $offset = ord($hash[19]) & 0x0F;
        $binary = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($binary % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    public static function stepFor(?int $time = null, int $period = 30): int
    {
        return intdiv($time ?? time(), $period);
    }

    /**
     * Verify a code, tolerating clock drift of +/- $window steps.
     *
     * @return int|null the matching time step (store it to reject replays), or null if invalid
     */
    public static function verify(string $secret, string $code, ?int $time = null, int $window = 1, int $digits = 6, int $period = 30): ?int
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (! preg_match('/^\d{'.$digits.'}$/', $code)) {
            return null;
        }

        $current = self::stepFor($time, $period);
        $match = null;
        for ($offset = -$window; $offset <= $window; $offset++) {
            // Compare every candidate in constant time; do not return early.
            if (hash_equals(self::codeAt($secret, $current + $offset, $digits), $code)) {
                $match = $current + $offset;
            }
        }

        return $match;
    }

    public static function uri(string $secret, string $account, string $issuer): string
    {
        return 'otpauth://totp/'.rawurlencode($issuer.':'.$account)
            .'?secret='.$secret
            .'&issuer='.rawurlencode($issuer)
            .'&algorithm=SHA1&digits=6&period=30';
    }
}
