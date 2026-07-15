<?php

namespace Modules\Sales\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Services\LazadaOrderService;
use Modules\Channel\Services\ShopeeOrderService;
use Modules\Channel\Services\TikTokOrderService;
use Modules\Sales\Models\SalesOrder;

class SyncShippingStatus extends Command
{
    protected $signature = 'sales:sync-shipping-status
                            {--stale-hours=2 : Skip order yang channel_updated_at masih baru (kurang dari N jam)}
                            {--limit=200 : Batas order yang diproses per run}';

    protected $description = 'Fallback polling status pengiriman marketplace (Shopee/TikTok/Lazada) untuk order in-transit yang tidak dapat webhook update.';

    public function handle(
        ShopeeOrderService $shopee,
        TikTokOrderService $tiktok,
        LazadaOrderService $lazada,
    ): int {
        $staleHours = (int) $this->option('stale-hours');
        $limit = (int) $this->option('limit');
        $threshold = now()->subHours($staleHours);

        $orders = SalesOrder::query()
            ->whereIn('status', ['shipped', 'in-transit', 'in_transit', 'delivering'])
            ->whereIn('source', ['shopee', 'tiktok', 'lazada'])
            ->whereNotNull('channel_shop_id')
            ->whereNotNull('channel_order_no')
            ->where(function ($q) use ($threshold) {
                $q->whereNull('channel_updated_at')
                    ->orWhere('channel_updated_at', '<', $threshold);
            })
            ->orderBy('channel_updated_at')
            ->limit($limit)
            ->get(['id', 'salesorder_no', 'source', 'channel_shop_id', 'channel_order_no', 'channel_updated_at']);

        $this->info("Sync {$orders->count()} order in-transit (stale >{$staleHours}h)...");
        $ok = 0;
        $fail = 0;

        foreach ($orders as $order) {
            $source = strtolower((string) $order->source);
            $shopId = (string) $order->channel_shop_id;
            $channelOrderNo = (string) $order->channel_order_no;

            try {
                match ($source) {
                    'shopee' => $shopee->pullOrderById($shopId, $channelOrderNo),
                    'tiktok' => $tiktok->pullOrderById($shopId, $channelOrderNo),
                    'lazada' => $lazada->pullOrderById($shopId, $channelOrderNo),
                    default  => null,
                };
                $ok++;
            } catch (\Throwable $e) {
                $fail++;
                Log::warning('sales:sync-shipping-status resync gagal', [
                    'order_id'      => $order->id,
                    'salesorder_no' => $order->salesorder_no,
                    'source'        => $source,
                    'error'         => $e->getMessage(),
                ]);
            }
        }

        $this->info("Selesai: {$ok} sukses, {$fail} gagal.");

        return self::SUCCESS;
    }
}
