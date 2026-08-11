<?php

namespace Modules\Product\Repositories;

use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductMerge;
use Modules\Product\Models\ProductMergeHidden;

class ProductMergeRepository
{

    public function catalogProducts(): LazyCollection
    {
        return Product::query()
            ->where('status', Product::STATUS_MASTER)
            ->select(['id', 'name', 'sku', 'category_id'])
            ->with([
                'variants:id,product_id,sku',
                'category:id,name',
            ])
            ->lazy();
    }

    public function liteProducts(): LazyCollection
    {
        return Product::query()
            ->where('status', Product::STATUS_MASTER)
            ->select(['id', 'name', 'sku'])
            ->with(['variants:id,product_id,sku'])
            ->lazy();
    }

    public function presentation(array $productIds): array
    {
        $mediaByProduct = [];
        $channelsByProduct = [];

        if (empty($productIds)) {
            return compact('mediaByProduct', 'channelsByProduct');
        }

        $products = Product::query()
            ->whereIn('id', $productIds)
            ->with([
                'media:id,product_id,url,is_primary,sort_order',
                'channelMappings:id,product_id,channel_shop_id',
                'channelMappings.channelShop:id,channel_id,shop_name',
                'channelMappings.channelShop.channel:id,name,code',
            ])
            ->get(['id']);

        foreach ($products as $p) {
            $mediaByProduct[$p->id] = $p->media
                ->map(fn ($m) => ['url' => $m->url, 'is_primary' => (bool) $m->is_primary])
                ->all();

            $channels = [];
            foreach ($p->channelMappings as $cm) {
                $shop = $cm->channelShop;
                if (! $shop) {
                    continue;
                }
                $channels[] = [
                    'channel_shop_id' => $cm->channel_shop_id,
                    'shop_name' => $shop->shop_name,
                    'channel_name' => $shop->channel->name ?? null,
                    'channel_code' => $shop->channel->code ?? null,
                ];
            }
            $channelsByProduct[$p->id] = $channels;
        }

        return compact('mediaByProduct', 'channelsByProduct');
    }

    public function mergeMap(): array
    {
        return ProductMerge::query()->pluck('master_name', 'product_id')->all();
    }

    public function mergeMapFor(array $productIds): array
    {
        return ProductMerge::query()
            ->whereIn('product_id', $productIds)
            ->pluck('master_name', 'product_id')
            ->all();
    }

    public function hiddenNames(): array
    {
        return ProductMergeHidden::query()->pluck('master_name')->all();
    }

    public function mergesWithProducts(): LazyCollection
    {
        return ProductMerge::query()
            ->with([
                'product:id,name,sku,status',
                'product.variants:id,product_id,sku',
                'product.channelMappings:id,product_id,channel_shop_id',
                'product.channelMappings.channelShop:id,channel_id,shop_name',
                'product.channelMappings.channelShop.channel:id,name,code',
            ])
            ->orderBy('master_name')
            ->orderBy('id')
            ->lazy();
    }

    public function existingProductIds(array $productIds): array
    {
        return Product::query()->whereIn('id', $productIds)->pluck('id')->all();
    }

    public function productNames(array $productIds): array
    {
        return Product::query()->whereIn('id', $productIds)->pluck('name', 'id')->all();
    }

    public function masterIdNames(): Collection
    {
        return Product::query()
            ->where('status', Product::STATUS_MASTER)
            ->get(['id', 'name']);
    }

    public function productIdsForMasters(array $masterNames): array
    {
        return ProductMerge::query()
            ->whereIn('master_name', $masterNames)
            ->pluck('product_id')
            ->all();
    }

    public function insertMergesIgnore(array $rows): int
    {
        return ProductMerge::query()->insertOrIgnore($rows);
    }

    public function insertMerges(array $rows): void
    {
        ProductMerge::query()->insert($rows);
    }

    public function deleteMergesForProducts(array $productIds): void
    {
        ProductMerge::query()->whereIn('product_id', $productIds)->delete();
    }

    public function deleteMergeByProduct(string $productId): void
    {
        ProductMerge::query()->where('product_id', $productId)->delete();
    }

    public function deleteMergesByMaster(string $masterName): int
    {
        return ProductMerge::query()->where('master_name', $masterName)->delete();
    }

    public function deleteMergesByMasters(array $masterNames): int
    {
        return ProductMerge::query()->whereIn('master_name', $masterNames)->delete();
    }

    public function mergesByMasters(array $masterNames): Collection
    {
        return ProductMerge::query()
            ->whereIn('master_name', $masterNames)
            ->get(['product_id', 'master_name']);
    }

    public function hiddenExistsAny(array $masterNames): bool
    {
        return ProductMergeHidden::query()->whereIn('master_name', $masterNames)->exists();
    }

    public function hiddenNamesIn(array $masterNames): array
    {
        return ProductMergeHidden::query()
            ->whereIn('master_name', $masterNames)
            ->pluck('master_name')
            ->all();
    }

    public function deleteHiddenExcept(array $masterNames, string $keep): void
    {
        ProductMergeHidden::query()
            ->whereIn('master_name', $masterNames)
            ->where('master_name', '!=', $keep)
            ->delete();
    }

    public function ensureHidden(string $masterName): void
    {
        ProductMergeHidden::query()->firstOrCreate(['master_name' => $masterName]);
    }

    public function insertHiddenIgnore(array $rows): int
    {
        return ProductMergeHidden::query()->insertOrIgnore($rows);
    }

    public function deleteHidden(array $masterNames): int
    {
        return ProductMergeHidden::query()->whereIn('master_name', $masterNames)->delete();
    }
}
