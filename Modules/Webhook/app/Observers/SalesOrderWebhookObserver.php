<?php

namespace Modules\Webhook\Observers;

use Modules\Sales\Models\SalesOrder;
use Modules\Webhook\Support\WebhookEvent;

class SalesOrderWebhookObserver extends AbstractWebhookObserver
{
    public function created(SalesOrder $order): void
    {

        $this->emit(WebhookEvent::SALES_ORDER, $this->payload($order, 'created'));
    }

    public function updated(SalesOrder $order): void
    {
        $statusChanged = $order->wasChanged('status');
        $shipmentChanged = $order->wasChanged('tracking_number') || $order->wasChanged('shipping_provider');

        if (! $statusChanged && ! $shipmentChanged) {
            return;
        }

        $this->emit(
            WebhookEvent::SALES_ORDER,
            $this->payload($order, $statusChanged ? 'status_changed' : 'shipment_updated')
        );
    }

    private function payload(SalesOrder $order, string $action): array
    {
        return [
            'id' => $order->id,
            'salesorder_no' => $order->salesorder_no,
            'source' => $order->source,
            'channel_shop_id' => $order->channel_shop_id,
            'status' => $order->status,
            'channel_status' => $order->channel_status,
            'tracking_number' => $order->tracking_number,
            'shipping_provider' => $order->shipping_provider,
            'grand_total' => $order->grand_total,
            'items' => $order->relationLoaded('items')
                ? $order->items->map(fn ($item) => [
                    'sku' => $item->sku,
                    'qty_in_base' => $item->qty_in_base,
                    'amount' => $item->amount,
                ])->all()
                : null,
            'action' => $action,
        ];
    }
}
