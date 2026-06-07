<?php

namespace Modules\Product\Observers;

use Illuminate\Support\Facades\Cache;
use Modules\Channel\Jobs\SyncProductToChannelJob;
use Modules\Product\Models\Product;

class ProductObserver
{
    /**
     * Handle the Product "updated" event.
     */
    public function updated(Product $product): void
    {
        // Debounce: If there's already a pending job for this product in the last 5 seconds, skip
        $debounceKey = "product_sync_debounce:{$product->id}";
        if (Cache::has($debounceKey)) {
            return;
        }
        Cache::put($debounceKey, true, 5);

        // Dispatch sync to all connected channels
        // Only for mappings that are not 'pending' (meaning they have been pushed before)
        foreach ($product->channelMappings()->where('sync_status', '!=', 'pending')->get() as $mapping) {
            SyncProductToChannelJob::dispatch($product->id, $mapping->channel_shop_id, 'update');
        }
    }
}
