<?php

namespace Modules\Sales\Observers;

use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Services\FinanceSyncControlService;

class SalesOrderFinanceResyncObserver
{
    public function updated(SalesOrder $order): void
    {
        $becameCanceled = ($order->wasChanged('is_canceled') && $order->is_canceled)
            || ($order->wasChanged('status') && $order->status === 'cancelled');

        if (! $becameCanceled) {
            return;
        }

        if (! $order->source || ! $order->channel_shop_id || ! $order->channel_order_no) {
            return;
        }

        app(FinanceSyncControlService::class)->dispatch($order, true);
    }
}
