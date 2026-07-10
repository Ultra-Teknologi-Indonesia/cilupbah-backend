<?php

namespace Modules\Sales\Listeners;

use Illuminate\Support\Facades\Log;
use Modules\Sales\Events\OrderNeedsBuyerConfirmation;

class OrderNeedsBuyerConfirmationListener
{
    public function handle(OrderNeedsBuyerConfirmation $event): void
    {
        Log::info('OrderNeedsBuyerConfirmation received', [
            'order_id' => $event->orderId,
            'picklist_id' => $event->picklistId,
            'short_items' => count($event->shortItems),
        ]);

    }
}
