<?php

namespace App\Support;

/** Exact money arithmetic on integer minor units (no floats, no bcmath dependency). */
final class Money
{
    public static function minor(string|int|float|null $amount, int $decimals = 2): int
    {
        return (int) round(((float) $amount) * (10 ** $decimals));
    }

    public static function format(int $minor, int $decimals = 2): string
    {
        return number_format($minor / (10 ** $decimals), $decimals, '.', '');
    }

    /** Percentage of an amount, rounded half up to the minor unit. */
    public static function percent(int $minor, string|float $percent): int
    {
        return (int) round($minor * ((float) $percent) / 100);
    }
}
