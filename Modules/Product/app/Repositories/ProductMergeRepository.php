<?php

namespace Modules\Product\Repositories;

use Illuminate\Database\Eloquent\Collection;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductMerge;
use Modules\Product\Models\ProductMergeHidden;

class ProductMergeRepository
{
    /**
     * Relasi yang dibutuhkan untuk membangun katalog & menampilkan cakupan
     * multi-store / multi-channel per produk.
     */
    private const CATALOG_RELATIONS = [
        'variants:id,product_id,sku,sell_price',
        'media:id,product_id,url,is_primary,sort_order',
        'category:id,name',
        'brand:id,name',
        'channelMappings:id,product_id,channel_shop_id',
        'channelMappings.channelShop:id,channel_id,shop_name',
        'channelMappings.channelShop.channel:id,name,code',
    ];

    /**
     * Semua produk Master (status = master) — kandidat katalog/merge.
     * Merge bersifat lintas-store/channel: TIDAK difilter per channel.
     */
    public function masterProducts(): Collection
    {
        return Product::query()
            ->where('status', Product::STATUS_MASTER)
            ->with(self::CATALOG_RELATIONS)
            ->get();
    }

    /** @return array<string,string> product_id => master_name */
    public function mergeMap(): array
    {
        return ProductMerge::query()->pluck('master_name', 'product_id')->all();
    }

    /** @return array<int,string> daftar master_name yang disembunyikan */
    public function hiddenNames(): array
    {
        return ProductMergeHidden::query()->pluck('master_name')->all();
    }

    /**
     * Merge aktif + produk anggotanya (untuk tab "Sudah Di-merge"),
     * lengkap dengan cakupan store/channel tiap produk.
     */
    public function mergesWithProducts(): Collection
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
            ->get();
    }
}
