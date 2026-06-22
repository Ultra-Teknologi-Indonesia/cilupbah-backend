<?php

namespace Modules\Sales\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Jobs\SyncStockToChannelsJob;
use Modules\Product\Models\ProductVariant;
use Modules\Sales\Models\SalesOrder;

class SyncStockJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [5, 15, 30];

    public function __construct(
        public readonly ?string $orderId,
        public readonly array $skuList = [],
    ) {
        $this->onQueue(config('queue.names.stock_sync'));
    }

    public function handle(): void
    {
        $skus = collect($this->skuList)->filter()->unique()->values();

        if ($skus->isEmpty() && $this->orderId) {
            $order = SalesOrder::with('items')->find($this->orderId);

            if (! $order) {
                Log::warning('SyncStockJob: order not found', ['order_id' => $this->orderId]);
                return;
            }

            $skus = $order->items->pluck('sku')->filter()->unique()->values();
        }

        if ($skus->isEmpty()) {
            return;
        }

        $variantIds = ProductVariant::whereIn('sku', $skus)->pluck('id');

        if ($variantIds->isEmpty()) {
            Log::warning('SyncStockJob: no variants found for SKUs', [
                'order_id' => $this->orderId,
                'skus'     => $skus->all(),
            ]);
            return;
        }

        foreach ($variantIds as $variantId) {
            SyncStockToChannelsJob::dispatch($variantId);

            Log::info('SyncStockJob: dispatched channel sync for variant', [
                'order_id'   => $this->orderId,
                'variant_id' => $variantId,
            ]);
        }
    }
}
