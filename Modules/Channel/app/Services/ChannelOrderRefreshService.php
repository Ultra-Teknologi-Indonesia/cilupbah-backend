<?php

declare(strict_types=1);

namespace Modules\Channel\Services;

use InvalidArgumentException;

final class ChannelOrderRefreshService
{
    public function __construct(
        private readonly ShopeeOrderService $shopee,
        private readonly TikTokOrderService $tiktok,
        private readonly LazadaOrderService $lazada,
        private readonly WooCommerceOrderService $woocommerce,
        private readonly ChannelSyncSettingService $settings,
    ) {}

    public function refresh(
        string $channel,
        string $shopId,
        string $orderId,
        bool $allowWhilePaused = false,
    ): int {
        if ($this->settings->isPaused() && ! $allowWhilePaused) {
            return 0;
        }

        $pulled = match (strtolower($channel)) {
            'shopee' => $this->shopee->pullOrderById($shopId, $orderId, deferFinance: true),
            'tiktok' => $this->tiktok->pullOrderById($shopId, $orderId),
            'lazada' => $this->lazada->pullOrderById($shopId, $orderId),
            'woocommerce' => $this->woocommerce->pullOrderById($shopId, $orderId),
            default => throw new InvalidArgumentException("Unsupported channel order refresh: {$channel}"),
        };

        return (int) $pulled;
    }

    public function refreshMany(
        string $channel,
        string $shopId,
        array $orderIds,
        bool $allowWhilePaused = false,
    ): array {
        $orderIds = array_values(array_unique(array_filter(array_map(
            static fn (mixed $orderId): string => trim((string) $orderId),
            $orderIds,
        ))));

        if ($orderIds === [] || ($this->settings->isPaused() && ! $allowWhilePaused)) {
            return ['pulled' => 0, 'failed' => $orderIds];
        }

        return match (strtolower($channel)) {
            'shopee' => $this->shopee->pullOrdersByIds($shopId, $orderIds, deferFinance: true),
            'tiktok' => $this->tiktok->pullOrdersByIds($shopId, $orderIds),
            'lazada' => $this->lazada->pullOrdersByIds($shopId, $orderIds),
            'woocommerce' => $this->refreshWooCommerceMany($shopId, $orderIds),
            default => throw new InvalidArgumentException("Unsupported channel order refresh: {$channel}"),
        };
    }

    private function refreshWooCommerceMany(string $shopId, array $orderIds): array
    {
        $pulled = 0;
        $failed = [];

        foreach ($orderIds as $orderId) {
            try {
                if ((int) $this->woocommerce->pullOrderById($shopId, $orderId) > 0) {
                    $pulled++;
                } else {
                    $failed[] = $orderId;
                }
            } catch (\Throwable) {
                $failed[] = $orderId;
            }
        }

        return ['pulled' => $pulled, 'failed' => $failed];
    }
}
