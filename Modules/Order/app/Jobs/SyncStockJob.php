<?php

namespace Modules\Order\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Services\TikTokProductService;
use Modules\Order\Models\Order;

class SyncStockJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [5, 15, 30];

    public function __construct(
        public readonly string $orderId,
    ) {
        $this->onQueue(config('queue.names.stock_sync'));
    }

    public function handle(TikTokProductService $tikTokProductService): void
    {
        $order = Order::with('items')->find($this->orderId);

        if (! $order) {
            Log::warning('SyncStockJob: order not found', ['order_id' => $this->orderId]);
            return;
        }

        $skus = $order->items->pluck('sku')->filter()->unique()->values();

        if ($skus->isEmpty()) {
            return;
        }

        $activeShops = DB::table('channel_shops')
            ->where('is_active', true)
            ->whereNotNull('access_token')
            ->get();

        foreach ($activeShops as $shop) {
            foreach ($skus as $sku) {
                try {
                    $tikTokProductService->syncInventoryBySku($sku, $shop->shop_id);

                    Log::info("SyncStockJob: synced {$sku} to shop {$shop->shop_name}", [
                        'order_id' => $this->orderId,
                        'shop_id'  => $shop->shop_id,
                        'sku'      => $sku,
                    ]);
                } catch (\Throwable $e) {
                    Log::error("SyncStockJob: failed to sync {$sku} to shop {$shop->shop_name}", [
                        'order_id'  => $this->orderId,
                        'shop_id'   => $shop->shop_id,
                        'sku'       => $sku,
                        'exception' => $e->getMessage(),
                    ]);
                }
            }
        }
    }
}
