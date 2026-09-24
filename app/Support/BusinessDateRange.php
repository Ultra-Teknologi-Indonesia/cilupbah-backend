<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;

final class BusinessDateRange
{
    public static function timezone(): string
    {
        return (string) config('app.business_timezone', 'Asia/Jakarta');
    }

    /**
     * Return today's calendar date in the business timezone.
     *
     * Date-only request defaults must not use the PHP process timezone (which
     * may be UTC in production), otherwise a late-night WIB request can be
     * assigned to the previous calendar day.
     */
    public static function today(): string
    {
        return CarbonImmutable::now(self::timezone())->toDateString();
    }

    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now(self::timezone());
    }

    public static function start(?string $date): ?CarbonImmutable
    {
        if ($date === null || trim($date) === '') {
            return null;
        }

        return self::parse($date)->startOfDay()->utc();
    }

    public static function endExclusive(?string $date): ?CarbonImmutable
    {
        if ($date === null || trim($date) === '') {
            return null;
        }

        $parsed = self::parse($date);

        return self::isDateOnly($date)
            ? $parsed->startOfDay()->addDay()->utc()
            : $parsed->utc()->addMicrosecond();
    }

    public static function bounds(?string $from, ?string $to): array
    {
        return [self::start($from), self::endExclusive($to)];
    }

    public static function isDateOnly(string $value): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value)) === 1;
    }

    private static function parse(string $value): CarbonImmutable
    {
        return CarbonImmutable::parse(trim($value), self::timezone());
    }
}
