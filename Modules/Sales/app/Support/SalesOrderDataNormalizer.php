<?php

declare(strict_types=1);

namespace Modules\Sales\Support;

use Ramsey\Uuid\Uuid;

final class SalesOrderDataNormalizer
{
    public static function money(mixed $value, int|float $default = 0): int|float
    {
        if ($value === null) {
            return $default;
        }

        if (is_string($value)) {
            $value = trim($value);
            if ($value === '' || $value === '-') {
                return $default;
            }
        }

        if (is_int($value) || is_float($value)) {
            return is_finite((float) $value) ? $value : $default;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        return $default;
    }

    public static function nullableUuid(mixed $value): ?string
    {
        if ($value === null || ! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);
        if ($value === '' || $value === '-') {
            return null;
        }

        return Uuid::isValid($value) ? $value : null;
    }

    public static function isInvalidUuid(mixed $value): bool
    {
        if ($value === null || ! is_scalar($value)) {
            return $value !== null;
        }

        if (trim((string) $value) === '') {
            return false;
        }

        return self::nullableUuid($value) === null;
    }
}
