<?php

namespace Modules\Webhook\Observers;

use Modules\Inventory\Models\Inventory;
use Modules\Webhook\Support\WebhookEvent;

class InventoryWebhookObserver extends AbstractWebhookObserver
{
    public function updated(Inventory $inventory): void
    {
        // Hanya saat stok bermakna berubah (on_hand/available), cek in-memory tanpa query.
        if (! $inventory->wasChanged('on_hand') && ! $inventory->wasChanged('available')) {
            return;
        }

        $this->emit(WebhookEvent::STOCK, [
            'item_id' => $inventory->item_id,
            'location_id' => $inventory->location_id,
            'on_hand' => (int) $inventory->on_hand,
            'reserved' => (int) $inventory->reserved,
            'available' => (int) $inventory->available,
        ]);
    }
}
