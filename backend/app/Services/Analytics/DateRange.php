<?php

namespace App\Services\Analytics;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/** A reporting window plus the equal-length window just before it (for period-over-period comparison). */
final class DateRange
{
    public const PRESETS = ['today', 'yesterday', 'last_7_days', 'last_30_days', 'last_90_days', 'this_month', 'last_month', 'this_year', 'custom'];

    public function __construct(public readonly CarbonImmutable $from, public readonly CarbonImmutable $to, public readonly string $preset) {}

    public static function resolve(?string $preset, ?string $from = null, ?string $to = null): self
    {
        $preset = $preset ?: 'last_30_days';
        $now = CarbonImmutable::now();
        [$f, $t] = match ($preset) {
            'today' => [$now->startOfDay(), $now->endOfDay()],
            'yesterday' => [$now->subDay()->startOfDay(), $now->subDay()->endOfDay()],
            'last_7_days' => [$now->subDays(6)->startOfDay(), $now->endOfDay()],
            'last_30_days' => [$now->subDays(29)->startOfDay(), $now->endOfDay()],
            'last_90_days' => [$now->subDays(89)->startOfDay(), $now->endOfDay()],
            'this_month' => [$now->startOfMonth(), $now->endOfDay()],
            'last_month' => [$now->subMonthNoOverflow()->startOfMonth(), $now->subMonthNoOverflow()->endOfMonth()],
            'this_year' => [$now->startOfYear(), $now->endOfDay()],
            'custom' => [self::date($from, 'from')->startOfDay(), self::date($to, 'to')->endOfDay()],
            default => throw ValidationException::withMessages(['range' => ['Unknown range.']]),
        };
        if ($f > $t) {
            throw ValidationException::withMessages(['from' => ['The start date must be before the end date.']]);
        }
        if ($f->diffInDays($t) > 800) {
            throw ValidationException::withMessages(['from' => ['The range is too long (max ~2 years).']]);
        }

        return new self($f, $t, $preset);
    }

    private static function date(?string $v, string $field): CarbonImmutable
    {
        try {
            if (blank($v)) {
                throw new \InvalidArgumentException;
            }

            return CarbonImmutable::parse((string) $v);
        } catch (\Throwable) {
            throw ValidationException::withMessages([$field => ['A valid date is required for a custom range.']]);
        }
    }

    public function previous(): self
    {
        $days = (int) $this->from->startOfDay()->diffInDays($this->to->startOfDay()) + 1;

        return new self($this->from->subDays($days), $this->from->subSecond(), 'previous');
    }

    /** day for ≤ 62 days, otherwise month */
    public function granularity(?string $requested = null): string
    {
        if (in_array($requested, ['day', 'month'], true)) {
            return $requested;
        }

        return $this->from->diffInDays($this->to) <= 62 ? 'day' : 'month';
    }

    /** @return list<string> every bucket label inside the range (so empty periods show as zero) */
    public function buckets(string $granularity): array
    {
        $out = [];
        $cursor = $granularity === 'day' ? $this->from->startOfDay() : $this->from->startOfMonth();
        while ($cursor <= $this->to) {
            $out[] = $cursor->format($granularity === 'day' ? 'Y-m-d' : 'Y-m');
            $cursor = $granularity === 'day' ? $cursor->addDay() : $cursor->addMonth();
        }

        return $out;
    }

    public function bucketOf(\DateTimeInterface $date, string $granularity): string
    {
        return $date->format($granularity === 'day' ? 'Y-m-d' : 'Y-m');
    }
}
