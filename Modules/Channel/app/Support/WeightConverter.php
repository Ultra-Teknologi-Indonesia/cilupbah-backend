<?php

namespace Modules\Channel\Support;

class WeightConverter
{

    public static function toKg($weight, ?string $unit): float
    {
        $w = (float) ($weight ?? 0);

        return in_array(strtolower((string) $unit), ['gram', 'g', 'gr']) ? $w / 1000 : $w;
    }
}
