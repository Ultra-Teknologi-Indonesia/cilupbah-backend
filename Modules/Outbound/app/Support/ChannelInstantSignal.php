<?php

declare(strict_types=1);

namespace Modules\Outbound\Support;

final class ChannelInstantSignal
{

    public static function normalizeType(?string $type): ?string
    {
        $value = strtolower(trim((string) $type));

        if ($value === '') {
            return null;
        }

        $compact = preg_replace('/[\s_-]+/', '', $value) ?: $value;

        return match (true) {
            $compact === 'instant' || str_contains($compact, 'instant') => 'INSTANT',
            $compact === 'sameday' || str_contains($compact, 'sameday') => 'SAME_DAY',
            $compact === 'regular' => 'REGULAR',
            $compact === 'standard' => 'STANDARD',
            in_array($compact, ['economy', 'economical'], true) => 'ECONOMY',
            $compact === 'express', $compact === 'nextday' => 'EXPRESS',
            in_array($compact, ['cargo', 'trucking', 'kargo'], true) => 'CARGO',
            preg_match('/\binstant\b|same[\s_-]*day/i', $value) === 1 => str_contains($compact, 'same') ? 'SAME_DAY' : 'INSTANT',
            preg_match('/\bregular\b|\bstandard\b|\beconomical\b|\beconomy\b|next[\s_-]*day|\bexpress\b|\bcargo\b|\btrucking\b|\bkargo\b/i', $value) === 1 => self::normalizeTypeToken($value),
            default => null,
        };
    }

    public static function isInstantType(?string $type): bool
    {
        return in_array(self::normalizeType($type), ['INSTANT', 'SAME_DAY'], true);
    }

    public static function fromTypes(?string ...$types): ?bool
    {
        $hasExplicitNonInstantType = false;

        foreach ($types as $type) {
            $normalized = self::normalizeType($type);

            if ($normalized === null) {
                continue;
            }

            if (self::isInstantType($normalized)) {
                return true;
            }

            $hasExplicitNonInstantType = true;
        }

        return $hasExplicitNonInstantType ? false : null;
    }

    private static function normalizeTypeToken(string $value): ?string
    {
        $compact = preg_replace('/[\s_-]+/', '', strtolower($value)) ?: strtolower($value);

        return match (true) {
            str_contains($compact, 'regular') => 'REGULAR',
            str_contains($compact, 'standard') => 'STANDARD',
            str_contains($compact, 'econom') => 'ECONOMY',
            str_contains($compact, 'nextday') => 'EXPRESS',
            str_contains($compact, 'express') => 'EXPRESS',
            str_contains($compact, 'cargo'), str_contains($compact, 'trucking'), str_contains($compact, 'kargo') => 'CARGO',
            default => null,
        };
    }
}
