<?php

namespace Modules\Channel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;

class SyncStockToChannelsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $variantId;

    /**
     * Create a new job instance.
     */
    public function __construct(string $variantId)
    {
        $this->variantId = $variantId;
        $this->onQueue(config('queue.names.channel_sync'));
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $variant = ProductVariant::with('product.channelMappings')->find($this->variantId);

        if (!$variant || !$variant->product) {
            return;
        }

        $product = $variant->product;

        // Dispatch sync job for each active channel mapping
        foreach ($product->channelMappings as $mapping) {
            if ($mapping->sync_status !== 'pending' && $mapping->sync_status !== 'deactivated') {
                SyncProductToChannelJob::dispatch(
                    $product->id, 
                    $mapping->channel_shop_id, 
                    'sync_price_stock'
                );
            }
        }
    }
}
