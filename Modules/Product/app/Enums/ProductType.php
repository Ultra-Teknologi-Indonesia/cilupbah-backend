<?php

namespace Modules\Product\Enums;

enum ProductType: string
{
    case SIMPLE = 'simple';
    case BUNDLE = 'bundle';

    public function isBundle(): bool
    {
        return $this === self::BUNDLE;
    }
}
