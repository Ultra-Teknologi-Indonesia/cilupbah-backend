<?php

namespace Modules\Sales\Enums;

enum OrderActivityEntity: string
{
    case ORDER = 'ORDER';
    case ITEM  = 'ITEM';

    public function label(): string
    {
        return match ($this) {
            self::ORDER => 'Pesanan',
            self::ITEM  => 'Item Pesanan',
        };
    }
}
