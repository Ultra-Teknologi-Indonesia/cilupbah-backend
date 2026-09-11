<?php

namespace Modules\Channel\Contracts;

use Modules\Channel\Models\ChannelShop;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductChannelMapping;

interface MarketplaceAdapterInterface
{
    public function getChannelCode(): string;

    public function pushProduct(Product $product, ChannelShop $shop, ?array $attributeMapping = null): array;

    public function updateProduct(Product $product, ChannelShop $shop, string $externalProductId): array;

    public function deleteProduct(ChannelShop $shop, string $externalProductId): array;

    public function activateProduct(ChannelShop $shop, string $externalProductId): array;

    public function deactivateProduct(ChannelShop $shop, string $externalProductId): array;

    public function syncPriceAndStock(
        Product $product,
        ChannelShop $shop,
        string $externalProductId,
        ?ProductChannelMapping $listing = null,
    ): array;

    public function syncStock(
        Product $product,
        ChannelShop $shop,
        string $externalProductId,
        ?ProductChannelMapping $listing = null,
    ): array;

    public function mapInboundProduct(array $channelData, string $shopId): array;
}
