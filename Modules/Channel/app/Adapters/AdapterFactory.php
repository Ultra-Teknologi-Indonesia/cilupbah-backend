<?php

namespace Modules\Channel\Adapters;

use Modules\Channel\Contracts\MarketplaceAdapterInterface;

class AdapterFactory
{
    public function make(string $channelCode): MarketplaceAdapterInterface
    {
        return match (strtolower($channelCode)) {
            'tiktok' => app(TikTokAdapter::class),
            'lazada' => app(LazadaAdapter::class),
            'shopee' => app(ShopeeAdapter::class),
            'woocommerce' => app(WooCommerceAdapter::class),

            default => throw new \InvalidArgumentException("Unsupported channel: {$channelCode}"),
        };
    }
}
