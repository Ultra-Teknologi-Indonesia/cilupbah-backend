<?php

namespace Modules\Channel\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Repositories\ChannelShopRepository;
use Modules\Channel\Services\LazadaOrderService;
use Modules\Channel\Services\OrderSyncStatusService;
use Modules\Channel\Services\ShopeeOrderService;
use Modules\Channel\Services\TikTokOrderService;
use Modules\Channel\Services\WooCommerceOrderService;

class EvaluateOrderSyncHealth extends Command
{
    protected $signature = 'channel:evaluate-order-sync {--no-pull : Lewati heartbeat pull, hanya evaluasi status turunan}';

    protected $description = 'Denyut + evaluasi status download pesanan per toko (Normal/Bermasalah/Tertunda)';

    public function handle(ChannelShopRepository $repo, OrderSyncStatusService $deriver): int
    {
        $shops = ChannelShop::query()
            ->whereNull('disconnected_at')
            ->with('channel')
            ->get();

        $pull = ! $this->option('no-pull')
            && app(\Modules\Channel\Services\ChannelSyncSettingService::class)->isEnabled();

        foreach ($shops as $shop) {
            if ($pull && $this->canHeartbeat($shop)) {
                $this->heartbeat($shop, $repo);
                $shop->refresh();
            }

            $derived = $deriver->derive($shop);
            $repo->setOrderSyncStatus($shop->id, $derived['status'], $derived['note']);
        }

        $this->info("Evaluasi order-sync selesai untuk {$shops->count()} toko.");

        return self::SUCCESS;
    }

    private function canHeartbeat(ChannelShop $shop): bool
    {
        return $shop->order_sync_enabled
            && ! empty($shop->access_token)
            && $shop->integration_status !== 'error'
            && $this->serviceFor($shop) !== null;
    }

    private function heartbeat(ChannelShop $shop, ChannelShopRepository $repo): void
    {
        try {
            $this->serviceFor($shop)->pullOrders($shop->shop_id);
            $repo->markOrderSyncOk($shop->id);
        } catch (\Throwable $e) {
            $repo->markOrderSyncProblem($shop->id, $e->getMessage());
            Log::warning('Heartbeat order-sync gagal', [
                'shop_id' => $shop->shop_id,
                'channel' => $shop->channel->code ?? null,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    private function serviceFor(ChannelShop $shop): mixed
    {
        return match ($shop->channel->code ?? null) {
            'shopee' => app(ShopeeOrderService::class),
            'tiktok', 'tokopedia' => app(TikTokOrderService::class),
            'lazada' => app(LazadaOrderService::class),
            'woocommerce' => app(WooCommerceOrderService::class),
            default => null,
        };
    }
}
