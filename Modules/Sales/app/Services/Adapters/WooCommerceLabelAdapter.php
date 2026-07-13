<?php

namespace Modules\Sales\Services\Adapters;

use Modules\Sales\Models\SalesOrder;

/**
 * STUB — WooCommerce native TIDAK menyediakan API cetak label pengiriman.
 * Label = tergantung plugin shipping yang dipakai merchant:
 *   - WooShip / WCS / WooCommerce Shipping (Automattic)
 *   - EasyShip, ShipStation, JNE, JNT, dst
 *
 * Setiap plugin punya endpoint/format sendiri; ada juga alur "manual"
 * (seller upload label kustom dari Seller Panel).
 *
 * Sementara belum ada info plugin yang dipakai merchant, adapter ini
 * throw ChannelUnsupportedException — item BulkShippingLabelItem akan
 * ditandai REASON_CHANNEL_UNSUPPORTED oleh BulkShippingLabelService.
 */
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
