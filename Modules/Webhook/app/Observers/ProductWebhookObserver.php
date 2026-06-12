<?php

namespace Modules\Webhook\Observers;

use Modules\Product\Models\Product;
use Modules\Webhook\Support\WebhookEvent;

class ProductWebhookObserver extends AbstractWebhookObserver
{
    public function created(Product $product): void
    {
        $this->emit(WebhookEvent::PRODUCT, [
            'id' => $product->id,
            'sku' => $product->sku,
            'name' => $product->name,
            'status' => $product->status,
        ]);
    }
}
