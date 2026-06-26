<?php

namespace Modules\Product\Observers;

use Illuminate\Support\Facades\Cache;
use Modules\Channel\Jobs\SyncProductToChannelJob;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductChannelMapping;

class ProductObserver
{

    private const LIFECYCLE_FIELDS = [
        'status',
        'archived_at',
        'archived_by',
        'archive_reason',
        'verified_at',
        'verified_by',
        'deleted_at',
        'updated_at',
    ];

    public function updated(Product $product): void
    {
        // Auto-pushing internal product edits to the marketplaces is disabled by
        // default: per-channel content (name/variations/media) is maintained directly
        // on each marketplace and downloaded back, so pushing master edits would
        // overwrite channel-specific data and trigger validation errors (e.g. TikTok's
        // 25-char name minimum). Stock sync is a separate path and is unaffected.
        if (! config('channel.auto_push_product_content', false)) {
            return;
        }

        if ($product->status === Product::STATUS_ARCHIVED) {
            return;
        }

        $changed = array_keys($product->getChanges());
        if ($changed !== [] && array_diff($changed, self::LIFECYCLE_FIELDS) === []) {
            return;
        }

        $debounceKey = "product_sync_debounce:{$product->id}";
        if (Cache::has($debounceKey)) {
            return;
        }
        Cache::put($debounceKey, true, 5);

        $mappings = $product->channelMappings()
            ->whereNotIn('sync_status', [
                ProductChannelMapping::STATUS_PENDING,
                ProductChannelMapping::STATUS_DEACTIVATED,
            ])
            ->get();

        foreach ($mappings as $mapping) {
            SyncProductToChannelJob::dispatch($product->id, $mapping->channel_shop_id, 'update');
        }
    }
}
