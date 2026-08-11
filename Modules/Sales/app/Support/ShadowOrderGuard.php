<?php

namespace Modules\Sales\Support;

use Illuminate\Support\Facades\Log;
use Modules\Sales\Models\SalesOrder;

/**
 * Order shadow ditarik dari marketplace hanya sebagai pembanding saat migrasi.
 * Order itu tidak boleh menggerakkan stok atau proses fulfillment apa pun di WMS.
 *
 * Guard ini sengaja mencatat setiap percobaan, supaya kalau ada operator atau
 * proses lain yang mencoba memproses order shadow, sinyalnya terlihat di log —
 * bukan gagal diam-diam.
 */
class ShadowOrderGuard
{
    public static function blocks(SalesOrder $order, string $action): bool
    {
        if (! $order->is_shadow) {
            return false;
        }

        Log::info('Aksi fulfillment dilewati karena order berstatus shadow.', [
            'salesorder_no' => $order->salesorder_no,
            'source'        => $order->source,
            'action'        => $action,
        ]);

        return true;
    }
}
