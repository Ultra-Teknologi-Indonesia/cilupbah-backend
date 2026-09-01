<?php

namespace Modules\Product\Repositories;

use App\Support\AllowedSearch;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Inventory\Support\StockSummary;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductBundleItem;
use Modules\Product\Models\ProductChannelMapping;
use Modules\Product\Models\ProductVariant;
use Modules\Product\Models\ProductWholesalePrice;
use Modules\Product\Support\TechnicalSku;
use Modules\Sales\Models\SalesOrderItem;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

class ProductRepository
{
    public function findBySku(string $sku, array $with = []): ?Product
    {
        $product = Product::with($with)->where('sku', $sku)->first();
        if ($product) {
            return $product;
        }

        $variant = TechnicalSku::exclude(ProductVariant::where('sku', $sku))->first();

        return $variant ? Product::with($with)->find($variant->product_id) : null;
    }

    public function paginateBundles(): LengthAwarePaginator
    {
        return QueryBuilder::for(Product::class)
            ->where('is_bundle', true)
            ->with(['variants', 'media', 'category'])
            ->allowedSearch('name')
            ->allowedFilters(AllowedFilter::exact('is_active'))
            ->allowedSorts('name', 'created_at')
            ->paginate(request('per_page', 20))
            ->appends(request()->query());
    }

    public function getByIdsWithStock(array $ids): Collection
    {
        return Product::with([
            'variants.inventories',
            'variants.inventories.bin:id,is_inbound',
            'bundleItems.component.inventories',
            'bundleItems.component.inventories.bin:id,is_inbound',
            'bundleItems.component.inventories.location:id,location_code,location_name',
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
            $product = $id ? Product::findOrFail($id) : new Product;

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

            if ($product->is_bundle && $product->is_active) {
                $this->ensureActiveBundleVariant($product);
            }

            return $product;
        });
    }

    public function ensureActiveBundleVariant(Product $product, string|int|float|null $sellPrice = null): ProductVariant
    {
        $activeVariant = $product->variants()
            ->where('is_active', true)
            ->first();

        if ($activeVariant) {
            if ($sellPrice !== null) {
                $activeVariant->update(['sell_price' => $sellPrice]);
            }

            return $activeVariant;
        }

        $existingVariant = $product->variants()
            ->where('is_internal', true)
            ->whereNull('deleted_at')
            ->orderBy('created_at')
            ->first();

        if ($existingVariant) {
            $updates = ['is_active' => true];
            if ($sellPrice !== null) {
                $updates['sell_price'] = $sellPrice;
            }
            $existingVariant->update($updates);

            return $existingVariant->refresh();
        }

        $technicalSku = '__bundle__'.$product->id;

        $existingTechnicalVariant = ProductVariant::withTrashed()
            ->where('sku', $technicalSku)
            ->first();

        if ($existingTechnicalVariant && $existingTechnicalVariant->product_id !== $product->id) {
            throw new \DomainException(
                "Kunci teknis bundle {$product->id} sudah digunakan item lain. "
                .'Periksa data duplikat sebelum menyimpan bundle.'
            );
        }

        if ($existingTechnicalVariant?->trashed()) {
            $existingTechnicalVariant->restore();
        }

        if ($existingTechnicalVariant) {
            $updates = [
                'is_active' => true,
                'is_internal' => true,
            ];
            if ($sellPrice !== null) {
                $updates['sell_price'] = $sellPrice;
            }
            $existingTechnicalVariant->update($updates);

            return $existingTechnicalVariant->refresh();
        }

        return $product->variants()->create([
            'sku' => $technicalSku,
            'sell_price' => $sellPrice ?? 0,
            'is_active' => true,
            'is_internal' => true,
        ]);
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
            if (InventoryMovement::whereIn('item_id', $variantIds)->exists()) {
                return 'sudah memiliki riwayat pergerakan stok';
            }

            if (SalesOrderItem::whereIn('item_id', $variantIds)->exists()) {
                return 'sudah memiliki transaksi penjualan';
            }
        }

        $hasActiveMapping = ProductChannelMapping::where('product_id', $productId)
            ->where('sync_status', '!=', ProductChannelMapping::STATUS_DEACTIVATED)
            ->exists();

        if ($hasActiveMapping) {
            return 'sudah memiliki mapping channel aktif';
        }

        return null;
    }

    public function bundleComponentsForVariant(string $variantId): ?array
    {
        $productId = ProductVariant::where('id', $variantId)->value('product_id');

        if ($productId !== null && Product::where('id', $productId)->value('is_bundle')) {
            return ProductBundleItem::where('bundle_product_id', $productId)
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

        return null;
    }

    public function bundleProductIdsUsingComponent(string $variantId): array
    {
        return ProductBundleItem::where('component_variant_id', $variantId)
            ->distinct()
            ->pluck('bundle_product_id')
            ->all();
    }

    public function paginateIndex(?string $status = null): LengthAwarePaginator
    {
        return QueryBuilder::for(Product::class)
            ->with(['variants', 'media', 'category', 'channelMappings.channelShop.channel'])
            ->allowedSearch('name')
            ->allowedFilters(
                AllowedFilter::exact('is_active')
            )
            ->allowedSorts('name', 'created_at')
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->paginate(request('per_page', 20))
            ->appends(request()->query());
    }

    public function paginateUploadable(string $channelShopId): LengthAwarePaginator
    {
        return QueryBuilder::for(Product::class)
            ->with(['variants', 'media', 'category'])
            ->where('status', Product::STATUS_MASTER)
            ->whereDoesntHave('channelMappings', fn ($query) => $query->where('channel_shop_id', $channelShopId))
            ->allowedSearch('name')
            ->allowedSorts('name', 'created_at')
            ->paginate(request('per_page', 20))
            ->appends(request()->query());
    }

    public function paginateVariants(string $productId): LengthAwarePaginator
    {
        return QueryBuilder::for(ProductVariant::class)
            ->where('product_id', $productId)
            ->tap(fn ($query) => TechnicalSku::exclude($query, 'product_variants.sku'))
            ->with(['options', 'media'])
            ->select('product_variants.*')
            ->addSelect(['inventories_sum_available' => DB::table('inventories')
                ->leftJoin('location_bins', 'location_bins.id', '=', 'inventories.bin_id')
                ->whereColumn('inventories.item_id', 'product_variants.id')
                ->selectRaw(StockSummary::availableSql())])
            ->allowedSearch('sku')
            ->allowedFilters(
                AllowedFilter::callback('option', fn ($q, $v) => $q->whereHas('options', fn ($o) => $o->where('value', 'ilike', "%{$v}%")))
            )
            ->allowedSorts(
                'sku',
                'sell_price',

                AllowedSort::callback('stock', fn ($q, bool $desc) => $q->orderByRaw('inventories_sum_available '.($desc ? 'desc' : 'asc').' nulls last'))
            )
            ->defaultSort('sku')
            ->paginate(request('per_page', 20))
            ->appends(request()->query());
    }

    public function paginateListedVariants(string $productId): LengthAwarePaginator
    {
        $channel = request('filter.channel');
        $shopId = request('filter.shop_id');
        $includeUnlisted = request()->boolean('include_unlisted');
        $search = request('search');

        $isBundle = Product::where('id', $productId)->value('is_bundle');

        $baseQuery = TechnicalSku::exclude(ProductVariant::query());
        AllowedSearch::apply($baseQuery, ['sku', 'options.value']);

        $query = QueryBuilder::for($baseQuery);

        if ($isBundle) {
            $componentVariantIds = ProductBundleItem::where('bundle_product_id', $productId)
                ->pluck('component_variant_id');
            $query->whereIn('id', $componentVariantIds);
        } else {
            $query->where('product_id', $productId);
        }

        return $query
            ->with(['options', 'channelMappings.channelMapping.channelShop.channel'])
            ->allowedFilters(
                AllowedFilter::callback('channel', fn ($q, $v) => $q->whereHas(
                    'channelMappings.channelMapping.channelShop.channel',
                    fn ($c) => $c->where('code', $v)
                )),
                AllowedFilter::callback('shop_id', fn ($q, $v) => $q->whereHas(
                    'channelMappings.channelMapping',
                    fn ($m) => $m->where('channel_shop_id', $v)
                ))
            )
            ->when(! $includeUnlisted && ! $channel && ! $shopId, fn ($q) => $q->whereHas('channelMappings.channelMapping'))
            ->allowedSorts('sku', 'sell_price')
            ->defaultSort('sku')
            ->paginate(request('per_page', 20))
            ->appends(request()->query());
    }

    public function paginatePriceBook(string $productId): LengthAwarePaginator
    {
        return QueryBuilder::for(ProductWholesalePrice::class)
            ->whereHas('variant', fn ($v) => TechnicalSku::exclude($v)->where('product_id', $productId))
            ->with('variant:id,sku')
            ->allowedSorts('min_qty', 'customer_type', 'price')
            ->defaultSort('variant_id', 'min_qty')
            ->paginate(request('per_page', 20))
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
