<?php

namespace Modules\Product\Observers;

use Illuminate\Support\Facades\Cache;
use Modules\Channel\Jobs\SyncStockToChannelsJob;
use Modules\Product\Models\ProductVariant;

class ProductVariantObserver
{
    private const PRICE_FIELDS = ['sell_price'];

    public function updated(ProductVariant $variant): void
    {
        $changed = array_keys($variant->getChanges());

        if (empty(array_intersect($changed, self::PRICE_FIELDS))) {
            return;
        }

        $debounceKey = "variant_price_sync_debounce:{$variant->id}";
        if (Cache::has($debounceKey)) {
            return;
        }
        Cache::put($debounceKey, true, 5);

        SyncStockToChannelsJob::dispatch((string) $variant->id)->afterCommit();
    }
}
