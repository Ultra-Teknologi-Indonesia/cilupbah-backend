<?php

namespace Modules\Product\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Modules\Channel\Models\ChannelShop;
use Modules\Product\Models\ChannelAttribute;
use Modules\Product\Models\ProductChannelDraft;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

class ProductChannelDraftRepository
{
    private const RELATIONS = [
        'product:id,name',
        'product.variants:id,product_id,sku',
        'product.media',
        'channelShop.channel',
    ];

    public function paginate(): LengthAwarePaginator
    {
        return QueryBuilder::for(ProductChannelDraft::class)
            ->select('product_channel_drafts.*')
            ->leftJoin('products', 'products.id', '=', 'product_channel_drafts.product_id')
            ->allowedSearch('products.name', 'products.sku')
            ->allowedFilters(
                AllowedFilter::callback('status', fn ($query, $value) => $query->where('product_channel_drafts.status', $value)),
                AllowedFilter::callback('channel', fn ($query, $value) => $query->whereHas('channelShop.channel', fn ($channel) => $channel->where('code', $value))),
                AllowedFilter::callback('shop_id', fn ($query, $value) => $query->whereHas('channelShop', fn ($shop) => $shop->where('shop_id', $value))),
                AllowedFilter::callback('product_id', fn ($query, $value) => $query->where('product_channel_drafts.product_id', $value)),
            )
            ->allowedSorts(
                AllowedSort::field('created_at', 'product_channel_drafts.created_at'),
                AllowedSort::field('updated_at', 'product_channel_drafts.updated_at'),
            )
            ->defaultSort('-created_at')
            ->with(self::RELATIONS)
            ->paginate(request('per_page', 20))
            ->appends(request()->query());
    }

    public function findDraft(string $id): ProductChannelDraft
    {
        return ProductChannelDraft::query()->findOrFail($id);
    }

    public function forProduct(string $productId): Collection
    {
        return QueryBuilder::for(ProductChannelDraft::class)
            ->where('product_id', $productId)
            ->allowedFilters(
                AllowedFilter::callback('status', fn ($query, $value) => $query->where('product_channel_drafts.status', $value)),
            )
            ->allowedSorts(
                AllowedSort::field('created_at', 'product_channel_drafts.created_at'),
                AllowedSort::field('updated_at', 'product_channel_drafts.updated_at'),
            )
            ->defaultSort('-created_at')
            ->with(self::RELATIONS)
            ->get();
    }

    public function findForProduct(string $productId, string $draftId): ?ProductChannelDraft
    {
        return ProductChannelDraft::query()
            ->where('id', $draftId)
            ->where('product_id', $productId)
            ->first();
    }

    public function findShopByShopId(string $shopId): ?ChannelShop
    {
        return ChannelShop::with('channel')->where('shop_id', $shopId)->first();
    }

    public function latestDraftForProductShop(string $productId, string $channelShopId): ?ProductChannelDraft
    {
        return ProductChannelDraft::where('product_id', $productId)
            ->where('channel_shop_id', $channelShopId)
            ->latest('updated_at')
            ->first();
    }

    public function requiredChannelAttributes(string $channelCategoryId): Collection
    {
        return ChannelAttribute::where('channel_category_id', $channelCategoryId)
            ->where('is_required', true)
            ->where('is_sale_prop', false)
            ->with('options')
            ->get();
    }
}
