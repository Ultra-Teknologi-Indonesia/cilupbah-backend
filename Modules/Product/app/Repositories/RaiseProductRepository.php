<?php

namespace Modules\Product\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Modules\Channel\Models\ChannelShop;
use Modules\Product\Models\ProductChannelMapping;
use Modules\Product\Models\RaiseProduct;
use Modules\Product\Models\RaiseProductDetail;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

class RaiseProductRepository
{
    private const RELATIONS = [
        'channelShop.channel',
    ];

    private const DETAIL_RELATIONS = [
        'channelMapping.channelShop.channel',
        'channelMapping.product.variants:id,product_id,sku',
        'channelMapping.product.media',
    ];

    public function paginate(): LengthAwarePaginator
    {
        return QueryBuilder::for(RaiseProduct::class)
            ->select('raise_products.*')
            ->leftJoin('channel_shops', 'channel_shops.id', '=', 'raise_products.channel_shop_id')
            ->withCount(['details as product_active_count' => fn ($query) => $query->where('is_active', true)])
            ->with(self::RELATIONS)
            ->allowedSearch('channel_shops.shop_name')
            ->allowedFilters(
                AllowedFilter::callback('channel', fn ($query, $value) => $query->whereHas('channelShop.channel', fn ($channel) => $channel->where('code', $value))),
                AllowedFilter::callback('shop_id', fn ($query, $value) => $query->whereHas('channelShop', fn ($shop) => $shop->where(function ($q) use ($value) {
                    if (\Illuminate\Support\Str::isUuid($value)) {
                        $q->where('id', $value)->orWhere('shop_id', $value);
                    } else {
                        $q->where('shop_id', $value);
                    }
                }))),
                AllowedFilter::callback('is_active', fn ($query, $value) => $query->where('raise_products.is_active', filter_var($value, FILTER_VALIDATE_BOOLEAN))),
            )
            ->allowedSorts(
                AllowedSort::field('created_at', 'raise_products.created_at'),
            )
            ->defaultSort('-raise_products.created_at')
            ->paginate(request('per_page', 20))
            ->appends(request()->query());
    }

    public function find(string $id): RaiseProduct
    {
        return RaiseProduct::query()
            ->with(self::RELATIONS)
            ->withCount(['details as product_active_count' => fn ($query) => $query->where('is_active', true)])
            ->findOrFail($id);
    }

    public function resolveChannelShopId(string $shopId): ?string
    {
        return ChannelShop::where('shop_id', $shopId)->value('id');
    }

    public function existsForShop(string $channelShopId): bool
    {
        return RaiseProduct::where('channel_shop_id', $channelShopId)->exists();
    }

    public function createHeader(array $data): RaiseProduct
    {
        return RaiseProduct::create($data);
    }

    public function deleteHeader(RaiseProduct $raiseProduct): void
    {
        $raiseProduct->delete();
    }

    public function findMapping(string $mappingId): ?ProductChannelMapping
    {
        return ProductChannelMapping::find($mappingId);
    }

    public function mappingIsRaised(string $mappingId): bool
    {
        return RaiseProductDetail::where('product_channel_mapping_id', $mappingId)->exists();
    }

    public function createDetail(array $data): RaiseProductDetail
    {
        return RaiseProductDetail::create($data);
    }

    public function findDetail(string $raiseProductId, string $detailId): ?RaiseProductDetail
    {
        return RaiseProductDetail::where('id', $detailId)
            ->where('raise_product_id', $raiseProductId)
            ->first();
    }

    public function findDetailFull(string $detailId): RaiseProductDetail
    {
        return RaiseProductDetail::query()
            ->with(self::DETAIL_RELATIONS)
            ->findOrFail($detailId);
    }

    public function updateDetail(RaiseProductDetail $detail, array $data): void
    {
        $detail->update($data);
    }

    public function deleteDetail(RaiseProductDetail $detail): void
    {
        $detail->delete();
    }

    public function markRaiseStart(string $raiseProductId, ?array $detailIds): void
    {
        RaiseProductDetail::query()
            ->where('raise_product_id', $raiseProductId)
            ->where('is_active', true)
            ->when($detailIds, fn ($query) => $query->whereIn('id', $detailIds))
            ->update(['start_time' => now(), 'end_time' => null, 'is_success' => null]);
    }

    public function detailsToRaise(string $raiseProductId, ?array $detailIds): Collection
    {
        return RaiseProductDetail::query()
            ->where('raise_product_id', $raiseProductId)
            ->where('is_active', true)
            ->when($detailIds, fn ($query) => $query->whereIn('id', $detailIds))
            ->with('channelMapping.channelShop.channel')
            ->get();
    }

    public function markRaiseResult(RaiseProductDetail $detail, bool $success, ?string $reason): void
    {
        $detail->update([
            'is_success' => $success,
            'reason' => $reason,
            'end_time' => now(),
        ]);
    }

    public function paginateDetails(string $raiseProductId): LengthAwarePaginator
    {
        return QueryBuilder::for(RaiseProductDetail::class)
            ->select('raise_product_details.*')
            ->where('raise_product_details.raise_product_id', $raiseProductId)
            ->leftJoin('product_channel_mappings', 'product_channel_mappings.id', '=', 'raise_product_details.product_channel_mapping_id')
            ->leftJoin('products', 'products.id', '=', 'product_channel_mappings.product_id')
            ->with(self::DETAIL_RELATIONS)
            ->allowedSearch('products.name')
            ->allowedFilters(
                AllowedFilter::callback('is_active', fn ($query, $value) => $query->where('raise_product_details.is_active', filter_var($value, FILTER_VALIDATE_BOOLEAN))),
                AllowedFilter::callback('is_success', fn ($query, $value) => $query->where('raise_product_details.is_success', filter_var($value, FILTER_VALIDATE_BOOLEAN))),
            )
            ->allowedSorts(
                AllowedSort::field('created_at', 'raise_product_details.created_at'),
            )
            ->defaultSort('raise_product_details.created_at')
            ->paginate(request('per_page', 20))
            ->appends(request()->query());
    }

    public function paginateHistory(string $raiseProductId): LengthAwarePaginator
    {
        return QueryBuilder::for(RaiseProductDetail::class)
            ->select('raise_product_details.*')
            ->where('raise_product_details.raise_product_id', $raiseProductId)
            ->whereNotNull('raise_product_details.is_success')
            ->leftJoin('product_channel_mappings', 'product_channel_mappings.id', '=', 'raise_product_details.product_channel_mapping_id')
            ->leftJoin('products', 'products.id', '=', 'product_channel_mappings.product_id')
            ->with(self::DETAIL_RELATIONS)
            ->allowedSearch('products.name')
            ->allowedSorts(
                AllowedSort::field('end_time', 'raise_product_details.end_time'),
            )
            ->defaultSort('-raise_product_details.end_time')
            ->paginate(request('per_page', 20))
            ->appends(request()->query());
    }
}
