<?php

namespace Modules\Sales\Services\Adapters;

use Modules\Sales\Models\SalesOrder;

class WooCommerceLabelAdapter implements ChannelLabelAdapter
{
    public function fetchLabel(SalesOrder $order, array $options): array
    {
        throw new ChannelUnsupportedException(
            'woocommerce_plugin_required',
            'Kanal WooCommerce: cetak label bergantung plugin shipping merchant. Butuh konfirmasi plugin yang dipakai (WooShip/EasyShip/dsb).',
        );
    }

    public function channel(): string
    {
        return 'woocommerce';
    }
}
