<?php

namespace Modules\Sales\Observers;

use Illuminate\Support\Facades\Log;
use Modules\Outbound\Services\ShipmentService;
use Modules\Sales\Models\SalesOrder;

class SalesOrderChannelStatusObserver
{

    private const DELIVERED_CHANNEL_STATUSES = [
        'TO_CONFIRM_RECEIVE',
        'DELIVERED',
        'COMPLETED',
    ];

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

        $this->stampReceivedDateIfDelivered($order);
    }

    private function stampReceivedDateIfDelivered(SalesOrder $order): void
    {
        if (! in_array(strtoupper((string) $order->channel_status), self::DELIVERED_CHANNEL_STATUSES, true)) {
            return;
        }
        if (! empty($order->received_date)) {
            return;
        }

        try {
            $order->updateQuietly(['received_date' => now()]);
        } catch (\Throwable $e) {
            Log::warning('SalesOrderChannelStatusObserver: gagal stamp received_date', [
                'order_id' => $order->id,
                'channel_status' => $order->channel_status,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
