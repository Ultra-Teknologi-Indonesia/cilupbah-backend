<?php

namespace Modules\Channel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Product\Models\ProductChannelMapping;

class ResyncShopStockJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $channelShopId;

    public function __construct(string $channelShopId)
    {
        $this->channelShopId = $channelShopId;
        $this->onQueue(config('queue.names.channel_stock'));
    }

    public function handle(): void
    {
        ProductChannelMapping::where('channel_shop_id', $this->channelShopId)
            ->where('sync_status', '!=', ProductChannelMapping::STATUS_DEACTIVATED)
            ->select(['id', 'product_id'])
            ->chunkById(500, function ($mappings): void {
                foreach ($mappings as $mapping) {
                    SyncProductToChannelJob::dispatch(
                        (string) $mapping->product_id,
                        $this->channelShopId,
                        'sync_price_stock'
                    );
                }
            });
    }
}
