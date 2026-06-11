<?php

namespace Modules\Webhook\Observers;

use Modules\Sales\Models\SalesOrder;
use Modules\Webhook\Support\WebhookEvent;

class SalesOrderWebhookObserver extends AbstractWebhookObserver
{
    public function created(SalesOrder $order): void
    {
        // Menangkap juga order yang berasal dari TikTok (pull → SalesOrder).
        $this->emit(WebhookEvent::SALES_ORDER, $this->payload($order, 'created'));
    }

    public function updated(SalesOrder $order): void
    {
        if (! $order->wasChanged('status')) {
            return;
        }

        $this->emit(WebhookEvent::SALES_ORDER, $this->payload($order, 'status_changed'));
    }

    private function payload(SalesOrder $order, string $action): array
    {
        return [
            'id' => $order->id,
            'salesorder_no' => $order->salesorder_no,
            'channel_shop_id' => $order->channel_shop_id,
            'status' => $order->status,
            'grand_total' => $order->grand_total,
            'action' => $action,
        ];
    }
}
