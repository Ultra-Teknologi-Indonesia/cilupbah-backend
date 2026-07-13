<?php

namespace Modules\Sales\Services\Adapters;

use Modules\Sales\Models\SalesOrder;

/**
 * STUB — Lazada Open Platform tidak memiliki satu endpoint print-label seragam
 * untuk seller lokal ID. Perlu riset:
 *  - Ada beberapa endpoint terkait (Cash On Delivery, Waybill) tergantung
 *    tipe fulfillment (LGF, FBS, sendiri) dan region seller.
 *  - Beberapa memerlukan flow pre-print di Seller Center yang tidak bisa
 *    langsung didownload via API tanpa langkah manual.
 *
 * Sementara fitur ini di-gate lewat exception, panggilnya akan dicatat sebagai
 * REASON_CHANNEL_UNSUPPORTED di BulkShippingLabelItem.
 */
class LazadaLabelAdapter implements ChannelLabelAdapter
{
    public function fetchLabel(SalesOrder $order, array $options): array
    {
        throw new ChannelUnsupportedException(
            'lazada_pending_research',
            'Kanal Lazada: cetak label otomatis belum diimplementasi. Butuh konfirmasi endpoint Waybill/COD dari akun aktif.',
        );
    }

    public function channel(): string
    {
        return 'lazada';
    }
}
