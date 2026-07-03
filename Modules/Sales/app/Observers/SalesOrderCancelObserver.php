<?php

namespace Modules\Sales\Observers;

use Modules\Sales\Jobs\AutoAcceptCancelRequestJob;
use Modules\Sales\Models\SalesOrder;

class SalesOrderCancelObserver
{
    public function updated(SalesOrder $order): void
    {
        $gainedCancelRequest = $order->wasChanged('cancel_requested_at')
            && $order->cancel_requested_at
            && ! $order->cancel_accepted_at;

        $becamePacked = $order->wasChanged('status')
            && $order->status === 'packed'
            && $order->cancel_requested_at
            && ! $order->cancel_accepted_at;

        if (! $gainedCancelRequest && ! $becamePacked) {
            return;
        }

        if ($order->status !== 'packed') {
            return;
        }

        AutoAcceptCancelRequestJob::dispatch($order->id);
    }
}
