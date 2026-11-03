<?php

namespace Tests\Unit;

use App\Support\Auth\Totp;
use PHPUnit\Framework\TestCase;

class TotpTest extends TestCase
{
    private const RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'; // base32("12345678901234567890")

    public function test_matches_rfc6238_sha1_vectors(): void
    {
        $vectors = [59 => '94287082', 1111111109 => '07081804', 1111111111 => '14050471', 1234567890 => '89005924', 2000000000 => '69279037'];

        foreach ($vectors as $time => $expected) {
            $this->assertSame($expected, Totp::codeAt(self::RFC_SECRET, intdiv($time, 30), 8), "time {$time}");
        }
    }

    public function test_base32_roundtrip(): void
    {
        $bytes = random_bytes(20);
        $this->assertSame($bytes, Totp::base32Decode(Totp::base32Encode($bytes)));
    }

    public function test_verify_accepts_adjacent_steps_and_rejects_others(): void
    {
        $t = 1_700_000_000;
        $code = Totp::codeAt(self::RFC_SECRET, Totp::stepFor($t));

        $this->assertSame(Totp::stepFor($t), Totp::verify(self::RFC_SECRET, $code, $t));
        $this->assertNotNull(Totp::verify(self::RFC_SECRET, $code, $t + 30));
        $this->assertNull(Totp::verify(self::RFC_SECRET, $code, $t + 120));
        $this->assertNull(Totp::verify(self::RFC_SECRET, 'abcdef', $t));
    }
}
