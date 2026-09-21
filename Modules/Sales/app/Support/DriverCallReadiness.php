<?php

declare(strict_types=1);

namespace Modules\Sales\Support;

use Modules\Sales\Models\SalesOrder;

final class DriverCallReadiness
{
    public static function ready(SalesOrder $order): bool
    {
        return filled($order->tracking_number)
            && $order->shipping_label_status === 'ready';
    }

    public static function markSucceededIfReady(SalesOrder $order): bool
    {
        $order->refresh();

        if (! self::ready($order)) {
            return false;
        }

        $order->update([
            'driver_call_status' => 'success',
            'driver_call_message' => null,
        ]);

        return true;
    }
}
