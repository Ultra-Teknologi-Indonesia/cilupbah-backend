<?php

namespace Modules\Report\Support;

class ChannelLabel
{
    public static function for(?string $source): string
    {
        return match ($source) {
            'shopee'      => 'SHOPEE',
            'tiktok'      => 'Shop | Tokopedia',
            'lazada'      => 'LAZADA',
            'tokopedia'   => 'Tokopedia',
            'woocommerce' => 'WooCommerce',
            'blibli'      => 'Blibli',
            'manual'      => 'Manual',
            'pos'         => 'POS',
            default       => $source ? ucfirst($source) : '',
        };
    }
}
