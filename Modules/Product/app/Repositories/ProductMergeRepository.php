<?php

namespace Modules\Product\Repositories;

use Illuminate\Support\LazyCollection;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductMerge;
use Modules\Product\Models\ProductMergeHidden;

class ProductMergeRepository
{

    private const CATALOG_RELATIONS = [
        'variants:id,product_id,sku,sell_price',
        'media:id,product_id,url,is_primary,sort_order',
        'category:id,name',
        'channelMappings:id,product_id,channel_shop_id',
        'channelMappings.channelShop:id,channel_id,shop_name',
        'channelMappings.channelShop.channel:id,name,code',
    ];

    public function masterProducts(): LazyCollection
    {
        return Product::query()
            ->where('status', Product::STATUS_MASTER)
            ->select(['id', 'name', 'sku', 'category_id'])
            ->with(self::CATALOG_RELATIONS)
            ->lazy();
    }

    public function mergeMap(): array
    {
        return ProductMerge::query()->pluck('master_name', 'product_id')->all();
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
}
