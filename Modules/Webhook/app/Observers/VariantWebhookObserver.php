<?php

namespace Modules\Webhook\Observers;

use Modules\Product\Models\ProductVariant;
use Modules\Webhook\Support\WebhookEvent;

class VariantWebhookObserver extends AbstractWebhookObserver
{
    public function updated(ProductVariant $variant): void
    {

        if (! $variant->wasChanged('sell_price')) {
            return;
        }

        $this->emit(WebhookEvent::PRICE, [
            'variant_id' => $variant->id,
            'product_id' => $variant->product_id,
            'sku' => $variant->sku,
            'sell_price' => $variant->sell_price,
        ]);
    }
}
