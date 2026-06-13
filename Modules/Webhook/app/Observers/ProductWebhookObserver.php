<?php

namespace Modules\Webhook\Observers;

use Modules\Product\Models\Product;
use Modules\Webhook\Support\WebhookEvent;

class ProductWebhookObserver extends AbstractWebhookObserver
{
    public function created(Product $product): void
    {
        $this->emit(WebhookEvent::PRODUCT, $this->payload($product, 'created'));
    }

    public function updated(Product $product): void
    {
        // Hanya saat status produk berubah (mis. download → in_review → master).
        if (! $product->wasChanged('status')) {
            return;
        }

        $this->emit(WebhookEvent::PRODUCT, $this->payload($product, 'status_changed'));
    }

    private function payload(Product $product, string $action): array
    {
        return [
            'id' => $product->id,
            'sku' => $product->sku,
            'name' => $product->name,
            'status' => $product->status,
            'action' => $action,
        ];
    }
}
