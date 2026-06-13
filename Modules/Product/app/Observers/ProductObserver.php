<?php

namespace Modules\Product\Observers;

use Illuminate\Support\Facades\Cache;
use Modules\Channel\Jobs\SyncProductToChannelJob;
use Modules\Product\Models\Product;

class ProductObserver
{

    public function updated(Product $product): void
    {

        $debounceKey = "product_sync_debounce:{$product->id}";
        if (Cache::has($debounceKey)) {
            return;
        }
        Cache::put($debounceKey, true, 5);

        foreach ($product->channelMappings()->where('sync_status', '!=', 'pending')->get() as $mapping) {
            SyncProductToChannelJob::dispatch($product->id, $mapping->channel_shop_id, 'update');
        }
    }
}
