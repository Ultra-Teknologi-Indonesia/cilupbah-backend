<?php

namespace Modules\Sales\Observers;

use Illuminate\Support\Facades\Log;
use Modules\Outbound\Services\ShipmentService;
use Modules\Sales\Models\SalesOrder;

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
