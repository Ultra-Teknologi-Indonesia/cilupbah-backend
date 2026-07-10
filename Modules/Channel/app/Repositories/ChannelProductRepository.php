<?php

namespace Modules\Channel\Repositories;

use Illuminate\Support\Facades\DB;
use Modules\Channel\Jobs\SyncProductToChannelJob;
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
        return optional($this->getChannelCategoryInfoByInternal($categoryId, $channelId))->external_id;
    }

    public function getChannelCategoryInfoByInternal(string $categoryId, string $channelId): ?object
    {
        $category = \Modules\Product\Models\Category::with([
            'channelCategories' => fn ($q) => $q->where('channel_id', $channelId)->orderByDesc('is_leaf'),
        ])->find($categoryId);

        if (! $category || $category->channelCategories->isEmpty()) {
            return null;
        }

        $chosen = $category->channelCategories->firstWhere('is_leaf', true)
            ?? $category->channelCategories->first();

        return (object) [
            'id' => $chosen->id,
            'external_id' => $chosen->external_id,
            'is_leaf' => (bool) $chosen->is_leaf,
        ];
    }

    public function getChannelCategoryInfoById(string $channelCategoryId): ?object
    {
        return DB::table('channel_categories')
            ->where('id', $channelCategoryId)
            ->select('id', 'external_id', 'is_leaf')
            ->first();
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

    public function getSalesAttributeMap(string $channelCategoryId): array
    {
        return DB::table('channel_attributes as ca')
            ->join('attribute_channel_mappings as m', 'm.channel_attribute_id', '=', 'ca.id')
            ->where('ca.channel_category_id', $channelCategoryId)
            ->where('ca.is_sale_prop', true)
            ->pluck('ca.external_id', 'm.attribute_id')
            ->all();
    }

    public function getSalePropNameMap(string $channelCategoryId): array
    {
        return DB::table('channel_attributes')
            ->where('channel_category_id', $channelCategoryId)
            ->where('is_sale_prop', true)
            ->get(['external_id', 'name'])
            ->mapWithKeys(fn ($a) => [mb_strtolower($a->name) => $a->external_id])
            ->all();
    }

    public function getChannelWarehouseByStore(string $shopId)
    {
        return DB::table('channel_warehouses')->where('store_id', $shopId)->first();
    }

    public function getAvailableQty(string $variantId, $locationId): int
    {

        $row = DB::table('inventories as i')
            ->leftJoin('location_bins as b', 'b.id', '=', 'i.bin_id')
            ->where('i.item_id', $variantId)
            ->where('i.location_id', $locationId)
            ->selectRaw('COALESCE(SUM(CASE WHEN b.id IS NOT NULL AND b.is_inbound = false THEN i.on_hand ELSE 0 END),0) as oh')
            ->selectRaw('COALESCE(SUM(i.reserved),0) as r')
            ->first();

        return max(0, (int) ($row->oh ?? 0) - (int) ($row->r ?? 0));
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
        ?array $channelAttributes = null,
        bool $pushInitialStock = false
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

        if ($pushInitialStock) {
            SyncProductToChannelJob::dispatch($productId, $channelShop->id, 'sync_price_stock')
                ->delay(now()->addSeconds(15));
        }

        return $pcmId;
    }

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
        $syncedPrice = null,
        ?string $salesAttributeId = null,
        ?string $salesAttributeName = null
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
            if ($salesAttributeId !== null) {
                $update['sales_attribute_id'] = $salesAttributeId;
            }
            if ($salesAttributeName !== null) {
                $update['sales_attribute_name'] = $salesAttributeName;
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
            'sales_attribute_id'         => $salesAttributeId,
            'sales_attribute_name'       => $salesAttributeName,
            'created_at'                 => $now,
            'updated_at'                 => $now,
        ]);
    }

    public function getVariantChannelMappings(string $productId, string $shopId): array
    {
        $channelShop = DB::table('channel_shops')->where('shop_id', $shopId)->first();
        if (!$channelShop) {
            return [];
        }

        return DB::table('product_variant_channel_mappings as pvcm')
            ->join('product_channel_mappings as pcm', 'pcm.id', '=', 'pvcm.product_channel_mapping_id')
            ->where('pcm.product_id', $productId)
            ->where('pcm.channel_shop_id', $channelShop->id)
            ->get(['pvcm.variant_id', 'pvcm.external_sku_id', 'pvcm.sales_attribute_id', 'pvcm.sales_attribute_name'])
            ->keyBy('variant_id')
            ->all();
    }
}
