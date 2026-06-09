<?php

namespace Modules\Product\Support;

class ChannelUrlBuilder
{
    public static function build(
        ?string $channelCode,
        ?string $externalProductId,
        ?string $shopId = null,
        ?string $skuId = null
    ): ?string {
        if ($channelCode === null || $externalProductId === null || $externalProductId === '') {
            return null;
        }

        return match (strtolower($channelCode)) {
            'tiktok' => "https://shop.tiktok.com/view/product/{$externalProductId}",
            'shopee' => ($shopId !== null && $shopId !== '')
                ? "https://shopee.co.id/product/{$shopId}/{$externalProductId}"
                : null,
            'lazada' => ($skuId !== null && $skuId !== '')
                ? "https://www.lazada.co.id/products/-i{$externalProductId}-s{$skuId}.html"
                : null,
            default => null,
        };
    }
}
