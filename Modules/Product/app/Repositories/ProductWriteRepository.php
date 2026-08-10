<?php

namespace Modules\Product\Repositories;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Product\Models\ProductVariant;
use Ramsey\Uuid\Uuid;

class ProductWriteRepository
{
    public function accountIdByMappingKey(string $mappingKey): ?string
    {
        return DB::table('account_mappings')->where('key', $mappingKey)->value('account_id');
    }

    public function taxRate($taxId): float
    {
        return (float) (DB::table('taxes')->where('id', $taxId)->value('rate') ?? 0);
    }

    public function syncUnlimitedShops(string $variantId, array $shopIds): void
    {
        $rows = [];
        foreach (array_unique($shopIds) as $shopId) {
            $rows[] = [
                'id' => Uuid::uuid7()->toString(),
                'variant_id' => $variantId,
                'channel_shop_id' => $shopId,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        if ($rows) {
            DB::table('variant_unlimited_shops')->insert($rows);
        }
    }

    public function deleteUnlimitedShops(string $variantId): void
    {
        DB::table('variant_unlimited_shops')->where('variant_id', $variantId)->delete();
    }

    public function productIdBySku(string $sku): ?string
    {
        return DB::table('products')->where('sku', $sku)->value('id');
    }

    public function productIdByVariantSku(string $sku): ?string
    {
        return DB::table('product_variants')->where('sku', $sku)->value('product_id');
    }

    public function productIdByChannelExternalId(string $shopId, string $externalProductId): ?string
    {
        return DB::table('product_channel_mappings as pcm')
            ->join('channel_shops as cs', 'cs.id', '=', 'pcm.channel_shop_id')
            ->where('cs.shop_id', $shopId)
            ->where('pcm.external_product_id', $externalProductId)
            ->value('pcm.product_id');
    }

    public function productCategoryId(string $productId)
    {
        return DB::table('products')->where('id', $productId)->value('category_id');
    }

    private function blankSkuToNull(array $row): array
    {
        if (array_key_exists('sku', $row) && is_string($row['sku']) && trim($row['sku']) === '') {
            $row['sku'] = null;
        }

        return $row;
    }

    public function updateProductRow(string $productId, array $data): void
    {
        $data = $this->blankSkuToNull($data);
        $data['updated_at'] = now();
        DB::table('products')->where('id', $productId)->update($data);
    }

    public function insertProductRow(array $row): void
    {
        DB::table('products')->insert($this->blankSkuToNull($row));
    }

    public function deleteProductLevelMedia(string $productId): void
    {
        DB::table('product_media')
            ->where('product_id', $productId)
            ->whereNull('variant_id')
            ->delete();
    }

    public function deleteVariantMedia(string $variantId): void
    {
        DB::table('product_media')->where('variant_id', $variantId)->delete();
    }

    public function insertMedia(array $rows): void
    {
        DB::table('product_media')->insert($rows);
    }

    public function findVariantByProductAndSku(string $productId, string $sku): ?object
    {
        return DB::table('product_variants')
            ->where('product_id', $productId)
            ->where('sku', $sku)
            ->first();
    }

    public function updateVariantRow(string $variantId, array $data): void
    {
        DB::table('product_variants')->where('id', $variantId)->update($this->blankSkuToNull($data));
    }

    public function insertVariantRow(array $row): void
    {
        DB::table('product_variants')->insert($this->blankSkuToNull($row));
    }

    public function variantIdByProductAndSku(string $productId, string $sku): ?string
    {
        return DB::table('product_variants')
            ->where('product_id', $productId)
            ->where('sku', $sku)
            ->value('id');
    }

    public function findOrCreateProductChannelMapping(string $productId, string $channelShopId): string
    {
        $pcm = DB::table('product_channel_mappings')
            ->where('product_id', $productId)
            ->where('channel_shop_id', $channelShopId)
            ->first();

        if ($pcm) {
            return $pcm->id;
        }

        $pcmId = Uuid::uuid7()->getHex()->toString();
        DB::table('product_channel_mappings')->insert([
            'id' => $pcmId,
            'product_id' => $productId,
            'channel_shop_id' => $channelShopId,
            'sync_status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $pcmId;
    }

    public function variationTypeAttributeIds(string $productId): array
    {
        return DB::table('product_variation_types')
            ->where('product_id', $productId)->pluck('attribute_id')
            ->map(fn ($v) => (int) $v)->all();
    }

    public function activeVariants(string $productId): Collection
    {
        return DB::table('product_variants')
            ->where('product_id', $productId)->where('is_active', true)
            ->get(['id', 'sku', 'sell_price']);
    }

    public function variantOptionsFor(array $variantIds): Collection
    {
        return DB::table('variant_options')
            ->whereIn('variant_id', $variantIds ?: ['00000000-0000-0000-0000-000000000000'])
            ->get(['variant_id', 'attribute_id', 'value']);
    }

    public function supersedeVariant(string $variantId): void
    {
        DB::table('product_variants')->where('id', $variantId)
            ->update(['is_active' => false, 'superseded_at' => now(), 'deleted_at' => now(), 'updated_at' => now()]);
    }

    public function freeInactiveVariantSkus(string $productId, array $skus): void
    {
        $skus = array_values(array_filter($skus, fn ($s) => $s !== null && $s !== ''));
        if (! $skus) {
            return;
        }

        DB::table('product_variants')
            ->where('product_id', $productId)
            ->where('is_active', false)
            ->whereNull('deleted_at')
            ->whereIn('sku', $skus)
            ->update(['deleted_at' => now(), 'updated_at' => now()]);
    }

    public function skusUsedByOtherProducts(string $productId, array $skus): array
    {
        $skus = array_values(array_filter($skus, fn ($s) => $s !== null && $s !== ''));
        if (! $skus) {
            return [];
        }

        return DB::table('product_variants')
            ->where('product_id', '!=', $productId)
            ->whereNull('deleted_at')
            ->whereIn('sku', $skus)
            ->pluck('sku')
            ->unique()
            ->values()
            ->all();
    }

    public function variationTypeExists(string $productId, int $attributeId): bool
    {
        return DB::table('product_variation_types')
            ->where('product_id', $productId)->where('attribute_id', $attributeId)->exists();
    }

    public function insertVariationType(array $row): void
    {
        DB::table('product_variation_types')->insert($row);
    }

    public function insertVariationTypes(array $rows): void
    {
        DB::table('product_variation_types')->insert($rows);
    }

    public function insertVariantOptions(array $rows): void
    {
        DB::table('variant_options')->insert($rows);
    }

    public function productSku(string $productId): ?string
    {
        return DB::table('products')->where('id', $productId)->value('sku');
    }

    public function variantSkuExists(string $sku): bool
    {
        return DB::table('product_variants')->where('sku', $sku)->exists();
    }

    public function deleteSpecifications(string $productId): void
    {
        DB::table('product_specifications')->where('product_id', $productId)->delete();
    }

    public function insertSpecifications(array $rows): void
    {
        DB::table('product_specifications')->insert($rows);
    }

    public function insertWholesalePrices(array $rows): void
    {
        DB::table('product_wholesale_prices')->insert($rows);
    }

    public function channelShopIdsForActiveMappings(string $productId): Collection
    {
        return DB::table('product_channel_mappings')
            ->where('product_id', $productId)
            ->where('sync_status', '!=', 'pending')
            ->pluck('channel_shop_id');
    }

    public function channelShopIdsForStockPriceSync(string $productId): Collection
    {
        return DB::table('product_channel_mappings')
            ->where('product_id', $productId)
            ->whereNotIn('sync_status', ['pending', 'deactivated'])
            ->whereNotNull('external_product_id')
            ->pluck('channel_shop_id');
    }

    public function categoryAttributesForValidation(int $categoryId): Collection
    {
        return DB::table('category_attributes as cga')
            ->join('attributes as a', 'a.id', '=', 'cga.attribute_id')
            ->where('cga.category_id', $categoryId)
            ->get(['cga.attribute_id', 'cga.is_required', 'a.type', 'a.name'])
            ->keyBy('attribute_id');
    }

    public function variantsForBulk(string $productId, array $ids): Collection
    {
        return ProductVariant::where('product_id', $productId)
            ->whereIn('id', $ids)
            ->get(['id', 'sku']);
    }

    public function countVariants(string $productId): int
    {
        return ProductVariant::where('product_id', $productId)->count();
    }

    public function setVariantsActive(array $ids, bool $active): void
    {
        ProductVariant::whereIn('id', $ids)
            ->update(['is_active' => $active, 'updated_at' => now()]);
    }

    public function variantHasChannelMapping(string $variantId): bool
    {
        return DB::table('product_variant_channel_mappings')->where('variant_id', $variantId)->exists();
    }

    public function variantHasInventory(string $variantId): bool
    {
        return DB::table('inventories')->where('item_id', $variantId)->exists();
    }

    public function deleteVariants(array $ids): void
    {
        ProductVariant::whereIn('id', $ids)->delete();
    }
}
