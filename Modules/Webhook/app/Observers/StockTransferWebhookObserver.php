<?php

namespace Modules\Webhook\Observers;

use Modules\Inventory\Models\InventoryTransfer;
use Modules\Webhook\Support\WebhookEvent;

class StockTransferWebhookObserver extends AbstractWebhookObserver
{
    public function created(InventoryTransfer $transfer): void
    {
        $this->emit(WebhookEvent::STOCK_TRANSFER, [
            'id' => $transfer->id,
            'transfer_number' => $transfer->transfer_number,
            'status' => $transfer->status,
        ]);
    }

    public function updated(InventoryTransfer $transfer): void
    {
        if (! $transfer->wasChanged('status')) {
            return;
        }

        $this->emit(WebhookEvent::STOCK_TRANSFER, [
            'id' => $transfer->id,
            'transfer_number' => $transfer->transfer_number,
            'status' => $transfer->status,
            'action' => 'status_changed',
        ]);
    }
}
