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
    ) {}

    public function refresh(string $channel, string $shopId, string $orderId): int
    {
        $pulled = match (strtolower($channel)) {
            'shopee' => $this->shopee->pullOrderById($shopId, $orderId),
            'tiktok' => $this->tiktok->pullOrderById($shopId, $orderId),
            'lazada' => $this->lazada->pullOrderById($shopId, $orderId),
            'woocommerce' => $this->woocommerce->pullOrderById($shopId, $orderId),
            default => throw new InvalidArgumentException("Unsupported channel order refresh: {$channel}"),
        };

        return (int) $pulled;
    }
}
