<?php

namespace Modules\Channel\Contracts;

use Modules\Product\Models\Product;
use Modules\Channel\Models\ChannelShop;

interface MarketplaceAdapterInterface
{

    public function getChannelCode(): string;

    public function pushProduct(Product $product, ChannelShop $shop, ?array $attributeMapping = null): array;

    public function updateProduct(Product $product, ChannelShop $shop, string $externalProductId): array;

    public function deleteProduct(ChannelShop $shop, string $externalProductId): array;

    public function activateProduct(ChannelShop $shop, string $externalProductId): array;

    public function deactivateProduct(ChannelShop $shop, string $externalProductId): array;

    public function syncPriceAndStock(Product $product, ChannelShop $shop, string $externalProductId): array;

    public function mapInboundProduct(array $channelData, string $shopId): array;
}
