<?php

namespace Modules\Channel\Services;

use Illuminate\Support\Facades\Log;
use Modules\Channel\Repositories\ChannelShopRepository;
use Modules\Sales\Jobs\ProcessChannelReturnJob;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesReturn;
use Modules\Sales\Repositories\SalesReturnRepository;

class ChannelReconciliationService
{
    public function __construct(
        private ShopeeOrderService $shopee,
        private TikTokOrderService $tiktok,
        private LazadaOrderService $lazada,
        private ChannelShopRepository $shops,
        private SalesReturnRepository $returns,
    ) {}

    private const CHANNELS = ['shopee', 'tiktok', 'lazada'];

    public function auditOrders(int $days = 2): array
    {
        $report = [];

        foreach (self::CHANNELS as $code) {
            foreach ($this->shops->getShopsByChannelCode($code) as $shop) {
                try {
                    $channelIds = $this->channelOrderIds($code, $shop->shop_id, $days);
                    if ($channelIds === null) {
                        continue;
                    }

                    $localIds = SalesOrder::query()
                        ->where('source', $code)
                        ->whereIn('channel_order_no', $channelIds)
                        ->pluck('channel_order_no')
                        ->all();

                    $missing = array_values(array_diff($channelIds, $localIds));

                    $report[] = [
                        'channel' => $code,
                        'shop_id' => (string) $shop->shop_id,
                        'channel_count' => count($channelIds),
                        'local_count' => count($localIds),
                        'missing_count' => count($missing),
                        'missing' => $missing,
                        'missing_sample' => array_slice($missing, 0, 10),
                    ];
                } catch (\Throwable $e) {
                    Log::warning("Reconcile audit gagal untuk {$code} {$shop->shop_id}: " . $e->getMessage());

                    $report[] = [
                        'channel' => $code,
                        'shop_id' => (string) $shop->shop_id,
                        'error' => $e->getMessage(),
                    ];
                }
            }
        }

        return $report;
    }

    public function pullMissingOrders(string $code, string $shopId, array $orderIds, int $limit = 50): array
    {
        $service = match ($code) {
            'shopee' => $this->shopee,
            'tiktok' => $this->tiktok,
            'lazada' => $this->lazada,
            default => null,
        };

        if ($service === null) {
            return ['pulled' => 0, 'failed' => 0, 'failed_ids' => []];
        }

        $pulled = 0;
        $failedIds = [];

        foreach (array_slice(array_values($orderIds), 0, $limit) as $orderId) {
            $orderId = (string) $orderId;

            try {
                if ((int) $service->pullOrderById($shopId, $orderId) > 0) {
                    $pulled++;

                    continue;
                }

                $failedIds[] = $orderId;
            } catch (\Throwable $e) {
                $failedIds[] = $orderId;
                Log::warning("Backfill order {$code} {$shopId} {$orderId} gagal: " . $e->getMessage());
            }
        }

        return ['pulled' => $pulled, 'failed' => count($failedIds), 'failed_ids' => $failedIds];
    }

    public function discoverShopeeReturns(int $maxPages = 10, int $pageSize = 50): array
    {
        $stats = [];

        foreach ($this->shops->getShopsByChannelCode('shopee') as $shop) {
            $seen = 0;
            $enqueued = 0;

            try {
                foreach ($this->shopee->listChannelReturns($shop->shop_id, $maxPages, $pageSize) as $return) {
                    $seen++;
                    $returnSn = $return['return_sn'];
                    $orderSn = $return['order_sn'];

                    if ($returnSn === '' || $orderSn === '') {
                        continue;
                    }

                    if ($this->returns->existsByChannelReturn(SalesReturn::SOURCE_MARKETPLACE, 'shopee:' . $returnSn)) {
                        continue;
                    }

                    ProcessChannelReturnJob::dispatch([
                        'source' => 'shopee',
                        'channel_order_id' => $orderSn,
                        'channel_return_id' => $returnSn,
                        'channel_shop_id' => (string) $shop->shop_id,
                        'reason' => $return['reason'] !== '' ? $return['reason'] : 'Retur Shopee',
                        'created_by' => 'system:shopee-discovery',
                    ]);

                    $enqueued++;
                }
            } catch (\Throwable $e) {
                Log::warning("Discovery retur Shopee gagal untuk {$shop->shop_id}: " . $e->getMessage());
            }

            $stats[] = [
                'shop_id' => (string) $shop->shop_id,
                'seen' => $seen,
                'enqueued' => $enqueued,
            ];
        }

        return $stats;
    }

    private function channelOrderIds(string $code, string $shopId, int $days): ?array
    {
        return match ($code) {
            'shopee' => $this->shopee->listRecentOrderIds($shopId, now()->subDays($days)->timestamp),
            'tiktok' => $this->tiktok->listRecentOrderIds($shopId),
            'lazada' => $this->lazada->listRecentOrderIds($shopId, now()->subDays($days)->toIso8601String()),
            default => null,
        };
    }
}
