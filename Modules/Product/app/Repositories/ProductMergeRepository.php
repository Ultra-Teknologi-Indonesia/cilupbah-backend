<?php

namespace Modules\Product\Repositories;

use Illuminate\Support\LazyCollection;
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
     *
     * Memakai lazy() (streaming per-chunk) + projeksi kolom induk supaya
     * tidak menahan ribuan model + relasinya di memori sekaligus. Eager load
     * tetap jalan per chunk (beda dari cursor() yang memicu N+1).
     */
    public function masterProducts(): LazyCollection
    {
        return Product::query()
            ->where('status', Product::STATUS_MASTER)
            ->select(['id', 'name', 'sku', 'category_id', 'brand_id'])
            ->with(self::CATALOG_RELATIONS)
            ->lazy();
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
            // id sebagai tiebreaker → total order yang deterministik, supaya
            // paginasi lazy() (offset-based) tidak skip/duplikat baris.
            ->orderBy('master_name')
            ->orderBy('id')
            ->lazy();
    }
}
