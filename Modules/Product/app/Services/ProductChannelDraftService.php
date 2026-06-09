<?php

namespace Modules\Product\Services;

use Modules\Product\Models\ProductChannelDraft;
use Modules\Channel\Models\ChannelShop;

class ProductChannelDraftService
{
    /**
     * Buat/perbarui draft listing untuk satu produk pada satu toko (unik per product+shop).
     */
    public function upsertDraft(string $productId, string $shopId, array $data, ?string $userId = null): ProductChannelDraft
    {
        $channelShopId = $this->requireChannelShopId($shopId);

        return ProductChannelDraft::updateOrCreate(
            [
                'product_id' => $productId,
                'channel_shop_id' => $channelShopId,
            ],
            array_filter([
                'channel_category_id' => $data['channel_category_id'] ?? null,
                'attribute_mapping' => $data['attribute_mapping'] ?? null,
                'price_override' => $data['price_override'] ?? null,
                'status' => $data['status'] ?? ProductChannelDraft::STATUS_DRAFT,
                'created_by' => $userId,
            ], fn ($value) => $value !== null)
        );
    }

    protected function requireChannelShopId(string $shopId): string
    {
        $channelShopId = ChannelShop::where('shop_id', $shopId)->value('id');

        if (!$channelShopId) {
            throw new \RuntimeException('Toko tidak ditemukan atau tidak aktif', 422);
        }

        return $channelShopId;
    }
}
