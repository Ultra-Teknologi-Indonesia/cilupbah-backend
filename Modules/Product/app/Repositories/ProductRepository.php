<?php

namespace Modules\Product\Repositories;

use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Product\Models\ProductWholesalePrice;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Spatie\QueryBuilder\QueryBuilder;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;

class ProductRepository
{

    public function findBySku(string $sku, array $with = []): ?Product
    {
        $product = Product::with($with)->where('sku', $sku)->first();
        if ($product) {
            return $product;
        }

        $variant = \Modules\Product\Models\ProductVariant::where('sku', $sku)->first();

        return $variant ? Product::with($with)->find($variant->product_id) : null;
    }

    public function paginateBundles(): LengthAwarePaginator
    {
        return QueryBuilder::for(Product::class)
            ->where('is_bundle', true)
            ->with(['variants', 'media', 'category', 'brand'])
            ->allowedSearch('name')
            ->allowedFilters(AllowedFilter::exact('is_active'))
            ->allowedSorts('name', 'created_at')
            ->paginate(request('per_page', 10))
            ->appends(request()->query());
    }

    public function getByIdsWithStock(array $ids): Collection
    {
        return Product::with([
            'variants.inventories',

            'bundleItems.component.inventories',
        ])->whereIn('id', $ids)->get();
    }

    public function getByIdsWithVariants(array $ids): Collection
    {
        return Product::with('variants:id,product_id,sku,sell_price')
            ->whereIn('id', $ids)
            ->get(['id', 'sku', 'name']);
    }

    public function saveBundle(?string $id, array $attributes, array $components): Product
    {
        return DB::transaction(function () use ($id, $attributes, $components) {
            $product = $id ? Product::findOrFail($id) : new Product();

            if (! $product->exists) {
                $product->status = Product::STATUS_MASTER;
                $product->is_active = true;
            }

            $product->fill($attributes)->save();

            $product->bundleItems()->delete();
            foreach ($components as $component) {
                $product->bundleItems()->create([
                    'component_variant_id' => $component['variant_id'],
                    'qty' => $component['qty'],
                ]);
            }

            return $product;
        });
    }

    public function variantIdsFromBundleProducts(array $variantIds): array
    {
        if (empty($variantIds)) {
            return [];
        }

        return ProductVariant::whereIn('id', $variantIds)
            ->whereHas('product', fn ($q) => $q->where('is_bundle', true))
            ->pluck('id')
            ->all();
    }

    public function currentIsBundle(string $productId): ?bool
    {
        $value = Product::where('id', $productId)->value('is_bundle');

        return $value === null ? null : (bool) $value;
    }

    public function transactionLockReason(string $productId): ?string
    {
        $variantIds = ProductVariant::where('product_id', $productId)->pluck('id');

        if ($variantIds->isNotEmpty()) {
            if (\Modules\Inventory\Models\InventoryMovement::whereIn('item_id', $variantIds)->exists()) {
                return 'sudah memiliki riwayat pergerakan stok';
            }

            if (\Modules\Sales\Models\SalesOrderItem::whereIn('item_id', $variantIds)->exists()) {
                return 'sudah memiliki transaksi penjualan';
            }
        }

        $hasActiveMapping = \Modules\Product\Models\ProductChannelMapping::where('product_id', $productId)
            ->where('sync_status', '!=', \Modules\Product\Models\ProductChannelMapping::STATUS_DEACTIVATED)
            ->exists();

        if ($hasActiveMapping) {
            return 'sudah memiliki mapping channel aktif';
        }

        return null;
    }

    public function bundleComponentsForVariant(string $variantId): ?array
    {
        $productId = ProductVariant::where('id', $variantId)->value('product_id');

        if ($productId === null || ! Product::where('id', $productId)->value('is_bundle')) {
            return null;
        }

        return \Modules\Product\Models\ProductBundleItem::where('bundle_product_id', $productId)
            ->with('component:id,sku')
            ->orderBy('component_variant_id')
            ->get()
            ->map(fn ($item) => [
                'variant_id' => $item->component_variant_id,
                'qty' => (int) $item->qty,
                'sku' => $item->component?->sku,
            ])
            ->all();
    }

    public function bundleProductIdsUsingComponent(string $variantId): array
    {
        return \Modules\Product\Models\ProductBundleItem::where('component_variant_id', $variantId)
            ->distinct()
            ->pluck('bundle_product_id')
            ->all();
    }

    public function paginateIndex(?string $status = null): LengthAwarePaginator
    {
        return QueryBuilder::for(Product::class)
            ->with(['variants', 'media', 'category', 'brand', 'channelMappings.channelShop.channel'])
            ->allowedSearch('name')
            ->allowedFilters(
                AllowedFilter::exact('is_active')
            )
            ->allowedSorts('name', 'created_at')
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->paginate(request('per_page', 10))
            ->appends(request()->query());
    }

    public function paginateUploadable(string $channelShopId): LengthAwarePaginator
    {
        return QueryBuilder::for(Product::class)
            ->with(['variants', 'media', 'category', 'brand'])
            ->where('status', Product::STATUS_MASTER)
            ->whereDoesntHave('channelMappings', fn ($query) => $query->where('channel_shop_id', $channelShopId))
            ->allowedSearch('name')
            ->allowedSorts('name', 'created_at')
            ->paginate(request('per_page', 10))
            ->appends(request()->query());
    }

    public function paginateVariants(string $productId): LengthAwarePaginator
    {
        return QueryBuilder::for(ProductVariant::class)
            ->where('product_id', $productId)
            ->with(['options', 'media'])
            ->withSum('inventories', 'available')
            ->allowedSearch('sku')
            ->allowedFilters(
                AllowedFilter::callback('option', fn ($q, $v) => $q->whereHas('options', fn ($o) => $o->where('value', 'ilike', "%{$v}%")))
            )
            ->allowedSorts(
                'sku',
                'sell_price',

                AllowedSort::callback('stock', fn ($q, bool $desc) => $q->orderByRaw('inventories_sum_available ' . ($desc ? 'desc' : 'asc') . ' nulls last'))
            )
            ->defaultSort('sku')
            ->paginate(request('per_page', 10))
            ->appends(request()->query());
    }

    public function paginateListedVariants(string $productId): LengthAwarePaginator
    {
        $channel = request('filter.channel');
        $includeUnlisted = request()->boolean('include_unlisted');

        return QueryBuilder::for(ProductVariant::class)
            ->where('product_id', $productId)
            ->with(['options', 'channelMappings.channelMapping.channelShop.channel'])
            ->allowedFilters(
                AllowedFilter::callback('channel', fn ($q, $v) => $q->whereHas(
                    'channelMappings.channelMapping.channelShop.channel',
                    fn ($c) => $c->where('code', $v)
                ))
            )
            ->when(! $includeUnlisted && ! $channel, fn ($q) => $q->whereHas('channelMappings.channelMapping'))
            ->allowedSorts('sku', 'sell_price')
            ->defaultSort('sku')
            ->paginate(request('per_page', 10))
            ->appends(request()->query());
    }

    public function paginatePriceBook(string $productId): LengthAwarePaginator
    {
        return QueryBuilder::for(ProductWholesalePrice::class)
            ->whereHas('variant', fn ($v) => $v->where('product_id', $productId))
            ->with('variant:id,sku')
            ->allowedSorts('min_qty', 'customer_type', 'price')
            ->defaultSort('variant_id', 'min_qty')
            ->paginate(request('per_page', 10))
            ->appends(request()->query());
    }

    public function findWithRelations(string $id, array $with = []): ?Product
    {
        return Product::with($with)->find($id);
    }

    public function getPaginatedProductsByChannel(string $channelShopId, int $limit = 20, ?string $syncStatus = null): LengthAwarePaginator
    {
        return QueryBuilder::for(Product::class)
            ->with(['variants:id,product_id,sku', 'channelMappings' => function ($q) use ($channelShopId) {
                $q->where('channel_shop_id', $channelShopId);
            }])
            ->whereHas('channelMappings', function ($q) use ($channelShopId, $syncStatus) {
                $q->where('channel_shop_id', $channelShopId);
                if ($syncStatus !== null) {
                    $q->where('sync_status', $syncStatus);
                }
            })
            ->allowedSearch('name')
            ->allowedFilters(
                AllowedFilter::exact('is_active')
            )
            ->allowedSorts('name', 'created_at')
            ->paginate($limit)
            ->appends(request()->query());
    }

    public function findByExternalId(string $externalId, string $channelShopId)
    {
        return Product::with(['variants:id,product_id,sku', 'channelMappings'])
            ->whereHas('channelMappings', function ($q) use ($externalId, $channelShopId) {
                $q->where('external_product_id', $externalId)
                  ->where('channel_shop_id', $channelShopId);
            })
            ->firstOrFail();
    }
}
