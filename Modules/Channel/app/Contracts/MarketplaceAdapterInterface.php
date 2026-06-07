<?php

namespace Modules\Channel\Contracts;

use Modules\Product\Models\Product;
use Modules\Channel\Models\ChannelShop;

interface MarketplaceAdapterInterface
{
    /**
     * Get the unique string code of the channel (e.g. 'tiktok', 'shopee')
     */
    public function getChannelCode(): string;

    // ==================== Product Sync ====================

    /**
     * Push a new product to the marketplace.
     * @return array [ 'success' => bool, 'external_product_id' => string, 'message' => string ]
     */
    public function pushProduct(Product $product, ChannelShop $shop): array;

    /**
     * Update an existing product in the marketplace.
     * @return array [ 'success' => bool, 'message' => string ]
     */
    public function updateProduct(Product $product, ChannelShop $shop, string $externalProductId): array;

    /**
     * Delete a product from the marketplace.
     * @return array [ 'success' => bool, 'message' => string ]
     */
    public function deleteProduct(ChannelShop $shop, string $externalProductId): array;

    /**
     * Activate / Publish a product in the marketplace.
     * @return array [ 'success' => bool, 'message' => string ]
     */
    public function activateProduct(ChannelShop $shop, string $externalProductId): array;

    /**
     * Deactivate / Unpublish a product in the marketplace.
     * @return array [ 'success' => bool, 'message' => string ]
     */
    public function deactivateProduct(ChannelShop $shop, string $externalProductId): array;

    // ==================== Stock & Price Sync ====================

    /**
     * Sync price and stock to the marketplace.
     * @return array [ 'success' => bool, 'message' => string ]
     */
    public function syncPriceAndStock(Product $product, ChannelShop $shop, string $externalProductId): array;

    // ==================== Inbound Mapping (Channel -> Internal) ====================

    /**
     * Transform inbound payload from marketplace to internal schema.
     */
    public function mapInboundProduct(array $channelData, string $shopId): array;
}
