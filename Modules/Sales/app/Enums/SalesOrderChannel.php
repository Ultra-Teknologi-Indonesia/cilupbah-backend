<?php

namespace Modules\Sales\Enums;

enum SalesOrderChannel: string
{
    case SHOPEE      = 'shopee';
    case TOKOPEDIA   = 'tokopedia';
    case TIKTOK      = 'tiktok';
    case LAZADA      = 'lazada';
    case WOOCOMMERCE = 'woocommerce';
    case BLIBLI      = 'blibli';
    case MANUAL      = 'manual';
    case POS         = 'pos';

    public function prefix(): string
    {
        return match ($this) {
            self::SHOPEE      => 'SP',
            self::TOKOPEDIA   => 'TP',
            self::TIKTOK      => 'TT',
            self::LAZADA      => 'LZ',
            self::WOOCOMMERCE => 'WC',
            self::BLIBLI      => 'BL',
            self::MANUAL      => 'MN',
            self::POS         => 'PS',
        };
    }

    public function isMarketplace(): bool
    {
        return ! in_array($this, [self::MANUAL, self::POS], true);
    }
}
