<?php

namespace Modules\Product\Repositories;

use Modules\Product\Models\Product;
use Illuminate\Pagination\LengthAwarePaginator;
use Spatie\QueryBuilder\QueryBuilder;
use Spatie\QueryBuilder\AllowedFilter;

class ProductRepository
{
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
