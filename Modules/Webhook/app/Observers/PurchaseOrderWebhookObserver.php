<?php

namespace Modules\Webhook\Observers;

use Modules\Purchase\Models\PurchaseOrder;
use Modules\Webhook\Support\WebhookEvent;

class PurchaseOrderWebhookObserver extends AbstractWebhookObserver
{
    public function created(PurchaseOrder $order): void
    {
        $this->emit(WebhookEvent::PURCHASE_ORDER, [
            'id' => $order->id,
            'po_number' => $order->po_number,
            'supplier_id' => $order->supplier_id,
            'status' => $order->status,
            'total_amount' => $order->total_amount,
        ]);
    }

    public function updated(PurchaseOrder $order): void
    {
        if (! $order->wasChanged('status')) {
            return;
        }

        $this->emit(WebhookEvent::PURCHASE_ORDER, [
            'id' => $order->id,
            'po_number' => $order->po_number,
            'status' => $order->status,
            'action' => 'status_changed',
        ]);
    }
}
