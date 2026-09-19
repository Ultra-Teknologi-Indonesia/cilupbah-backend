<?php

declare(strict_types=1);

namespace Modules\Sales\Support;

use Illuminate\Support\Facades\Log;
use Modules\Sales\Models\SalesOrder;

final class ChannelOrderSideEffectGuard
{
    public static function active(string $orderId, string $operation): ?SalesOrder
    {
        $order = SalesOrder::query()->find($orderId);

        if ($order === null) {
            Log::info('Channel side effect skipped because order no longer exists.', [
                'order_id' => $orderId,
                'operation' => $operation,
            ]);

            return null;
        }

        if ($order->is_canceled
            || $order->status === 'cancelled'
            || $order->channel_cancel_status === 'accepted') {
            Log::info('Channel side effect skipped because cancellation is final.', [
                'order_id' => $order->id,
                'salesorder_no' => $order->salesorder_no,
                'operation' => $operation,
                'status' => $order->status,
                'is_canceled' => $order->is_canceled,
                'channel_cancel_status' => $order->channel_cancel_status,
            ]);

            return null;
        }

        return $order;
    }
}
