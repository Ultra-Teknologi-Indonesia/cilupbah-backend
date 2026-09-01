<?php

namespace Modules\Outbound\Support;

final class FilterValues
{

    public static function list(mixed $value): array
    {
        $values = is_array($value) ? $value : explode(',', (string) $value);

        return array_values(array_filter(
            array_map(static fn (mixed $item): string => trim((string) $item), $values),
            static fn (string $item): bool => $item !== '',
        ));
    }
}
