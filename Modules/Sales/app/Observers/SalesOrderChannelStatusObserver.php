<?php

namespace Modules\Sales\Observers;

use Illuminate\Support\Facades\Log;
use Modules\Outbound\Services\ShipmentService;
use Modules\Sales\Models\SalesOrder;

/**
 * Setiap kali channel_status marketplace berubah (dari webhook / reconcile / poll),
 * teruskan ke ShipmentService untuk maju-kan status shipment lokal.
 * Idempotent & forward-only — aman dipanggil berkali-kali dengan nilai sama.
 */
class SalesOrderChannelStatusObserver
{
    public function updated(SalesOrder $order): void
    {
        if (! $order->wasChanged('channel_status')) {
            return;
        }
        if (empty($order->channel_status)) {
            return;
        }

        try {
            app(ShipmentService::class)->syncFromChannelStatus($order);
        } catch (\Throwable $e) {
            Log::warning('SalesOrderChannelStatusObserver: sync gagal', [
                'order_id' => $order->id,
                'channel_status' => $order->channel_status,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
