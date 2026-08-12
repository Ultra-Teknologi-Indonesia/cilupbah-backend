<?php

namespace Modules\Sales\Support;

use Illuminate\Support\Facades\Log;
use Modules\Sales\Models\SalesOrder;

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
