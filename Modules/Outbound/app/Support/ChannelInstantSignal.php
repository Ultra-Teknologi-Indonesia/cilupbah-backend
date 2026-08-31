<?php

declare(strict_types=1);

namespace Modules\Outbound\Support;

final class ChannelInstantSignal
{
    /**
     * Resolve the instant flag from channel-owned type/category fields.
     * A null result means the channel did not provide a usable category.
     */
    public static function fromTypes(?string ...$types): ?bool
    {
        $hasExplicitNonInstantType = false;

        foreach ($types as $type) {
            $value = strtolower(trim((string) $type));

            if ($value === '') {
                continue;
            }

            if (preg_match('/\binstant\b|same[\s_-]*day/i', $value) === 1) {
                return true;
            }

            if (preg_match('/\bregular\b|\bstandard\b|\beconomical\b|next[\s_-]*day|\bexpress\b|\bcargo\b|\btrucking\b|\bkargo\b/i', $value) === 1) {
                $hasExplicitNonInstantType = true;
            }
        }

        return $hasExplicitNonInstantType ? false : null;
    }
}
