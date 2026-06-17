<?php

namespace Modules\Channel\Repositories;

use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

class ChannelProductRepository
{
    public function getActiveProducts()
    {
        return DB::table('products')->where('is_active', true)->get();
    }

    public function getUnsyncedProducts(string $shopId)
    {
        $channelShop = DB::table('channel_shops')->where('shop_id', $shopId)->first();

        $mappedProductIds = $channelShop
            ? DB::table('product_channel_mappings')
                ->where('channel_shop_id', $channelShop->id)
                ->pluck('product_id')
            : collect();

        return DB::table('products')
            ->whereNotIn('id', $mappedProductIds)
            ->get();
    }

    public function getVariantBySku(string $sku)
    {
        return DB::table('product_variants')->where('sku', $sku)->first();
    }

    public function getVariantByProductIdAndSku(string $productId, string $sku)
    {
        return DB::table('product_variants')
            ->where('product_id', $productId)
            ->where('sku', $sku)
            ->first();
    }

    public function findById(string $id)
    {
        return DB::table('products')->where('id', $id)->first();
    }

    public function getVariantsByProductId(string $productId)
    {
        return DB::table('product_variants')->where('product_id', $productId)->get();
    }

    public function getMediaByProductId(string $productId)
    {
        return DB::table('product_media')->where('product_id', $productId)->get();
    }

    public function getVariantOptions(string $variantId)
    {
        return DB::table('variant_options')
            ->leftJoin('attributes', 'attributes.id', '=', 'variant_options.attribute_id')
            ->where('variant_options.variant_id', $variantId)
            ->select('variant_options.*', 'attributes.name as attribute_name')
            ->get()
            ->toArray();
    }

    public function getRawVariantOptions(string $variantId)
    {
        return DB::table('variant_options')->where('variant_id', $variantId)->get()->toArray();
    }

    public function getChannelCategoryExternalId(string $categoryId, string $channelId): ?string
    {
        $category = \Modules\Product\Models\Category::with([
            'channelCategories' => fn ($q) => $q->where('channel_id', $channelId),
        ])->find($categoryId);

        if ($category && $category->channelCategories->isNotEmpty()) {
            return $category->channelCategories->first()->external_id;
        }

        return null;
    }

    public function getProductSpecifications(string $productId)
    {
        return DB::table('product_specifications')->where('product_id', $productId)->get();
    }

    public function getAttributeChannelMapping(string $attributeId)
    {
        return DB::table('attribute_channel_mappings')->where('attribute_id', $attributeId)->first();
    }

    public function getChannelAttribute(string $id)
    {
        return DB::table('channel_attributes')->where('id', $id)->first();
    }

    public function getAttributeOptionChannelMapping(string $attributeOptionId)
    {
        return DB::table('attribute_option_channel_mappings')->where('attribute_option_id', $attributeOptionId)->first();
    }

    public function getChannelAttributeOption(string $id)
    {
        return DB::table('channel_attribute_options')->where('id', $id)->first();
    }

    public function getChannelWarehouseByStore(string $shopId)
    {
        return DB::table('channel_warehouses')->where('store_id', $shopId)->first();
    }

    public function getAvailableQty(string $variantId, $locationId): int
    {
        return (int) DB::table('inventories')
            ->where('item_id', $variantId)
            ->where('location_id', $locationId)
            ->sum('available');
    }

    public function getExternalProductId(string $productId, string $shopId): ?string
    {
        $channelShop = DB::table('channel_shops')->where('shop_id', $shopId)->first();
        if (!$channelShop) {
            return null;
        }

        $mapping = DB::table('product_channel_mappings')
            ->where('product_id', $productId)
            ->where('channel_shop_id', $channelShop->id)
            ->first();

        return $mapping->external_product_id ?? null;
    }

    public function updateChannelProductId(string $productId, string $channelProductId, string $shopId): void
    {
        $this->upsertChannelMapping($productId, $shopId, $channelProductId, 'synced');
    }

    public function upsertChannelMapping(
        string $productId,
        string $shopId,
        ?string $externalProductId = null,
        string $syncStatus = 'synced',
        ?array $channelAttributes = null
    ): string {
        $channelShop = DB::table('channel_shops')->where('shop_id', $shopId)->first();
        if (!$channelShop) {
            throw new \Exception("Channel shop tidak ditemukan untuk shop_id: {$shopId}");
        }

        $now = now();
        $attributesJson = self::canonicalAttributes($channelAttributes);

        $existing = DB::table('product_channel_mappings')
            ->where('product_id', $productId)
            ->where('channel_shop_id', $channelShop->id)
            ->first();

        if ($existing) {
            $update = [
                'sync_status'    => $syncStatus,
                'last_synced_at' => $now,
                'updated_at'     => $now,
            ];

            if ($externalProductId !== null) {
                $update['external_product_id'] = $externalProductId;
            }
            if ($attributesJson !== null) {
                $update['channel_attributes'] = $attributesJson;
            }

            DB::table('product_channel_mappings')
                ->where('id', $existing->id)
                ->update($update);

            return $existing->id;
        }

        $pcmId = Uuid::uuid7()->getHex()->toString();
        DB::table('product_channel_mappings')->insert([
            'id'                  => $pcmId,
            'product_id'          => $productId,
            'channel_shop_id'     => $channelShop->id,
            'external_product_id' => $externalProductId,
            'channel_attributes'  => $attributesJson,
            'sync_status'         => $syncStatus,
            'last_synced_at'      => $now,
            'created_at'          => $now,
            'updated_at'          => $now,
        ]);

        return $pcmId;
    }

    /**
     * Normalisasi atribut channel ke JSON kanonik (key tersortir) agar dua toko
     * dengan atribut sama (urutan beda) dianggap seragam.
     */
    public static function canonicalAttributes(?array $attributes): ?string
    {
        if (empty($attributes)) {
            return null;
        }

        $normalized = [];
        foreach ($attributes as $key => $value) {
            $normalized[(string) $key] = is_array($value) ? implode(', ', $value) : (string) $value;
        }
        ksort($normalized);

        return json_encode($normalized);
    }

    public function upsertVariantChannelMapping(
        string $pcmId,
        string $variantId,
        ?string $externalSkuId = null,
        ?string $channelSellerSku = null,
        $syncedPrice = null
    ): void {
        $now = now();

        $existing = DB::table('product_variant_channel_mappings')
            ->where('product_channel_mapping_id', $pcmId)
            ->where('variant_id', $variantId)
            ->first();

        if ($existing) {
            $update = ['updated_at' => $now];
            if ($externalSkuId !== null) {
                $update['external_sku_id'] = $externalSkuId;
            }
            if ($channelSellerSku !== null) {
                $update['channel_seller_sku'] = $channelSellerSku;
            }
            if ($syncedPrice !== null) {
                $update['synced_price'] = $syncedPrice;
            }
            DB::table('product_variant_channel_mappings')
                ->where('id', $existing->id)
                ->update($update);
            return;
        }

        DB::table('product_variant_channel_mappings')->insert([
            'id'                         => Uuid::uuid7()->getHex()->toString(),
            'product_channel_mapping_id' => $pcmId,
            'variant_id'                 => $variantId,
            'external_sku_id'            => $externalSkuId,
            'channel_seller_sku'         => $channelSellerSku,
            'synced_price'               => $syncedPrice,
            'created_at'                 => $now,
            'updated_at'                 => $now,
        ]);
    }
}
