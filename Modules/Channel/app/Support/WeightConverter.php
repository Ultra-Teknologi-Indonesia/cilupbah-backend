<?php

namespace Modules\Channel\Support;

class WeightConverter
{

    public static function toKg($weight, ?string $unit): float
    {
        $w = (float) ($weight ?? 0);

        return strtolower((string) $unit) === 'gram' ? $w / 1000 : $w;
    }
}
