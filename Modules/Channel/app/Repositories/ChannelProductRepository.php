<?php

namespace Modules\Channel\Repositories;

use Illuminate\Support\Facades\DB;

class ChannelProductRepository
{
    public function getActiveProducts()
    {
        return DB::table('products')->where('is_active', true)->get();
    }

    public function getAllProducts()
    {
        return DB::table('products')->orderBy('id', 'desc')->get();
    }

    public function getUnsyncedProducts()
    {
        return DB::table('products')->whereNull('channel_product_id')->get();
    }

    public function getVariantBySku(string $sku)
    {
        return DB::table('product_variants')->where('sku', $sku)->first();
    }

    public function findById(int $id)
    {
        return DB::table('products')->where('id', $id)->first();
    }

    public function getVariantsByProductId(int $productId)
    {
        return DB::table('product_variants')->where('product_id', $productId)->get();
    }

    public function getMediaByProductId(int $productId)
    {
        return DB::table('product_media')->where('product_id', $productId)->get();
    }

    public function getVariantOptions(int $variantId)
    {
        return DB::table('variant_options')->where('variant_id', $variantId)->get()->toArray();
    }

    public function updateChannelProductId(int $productId, string $channelProductId)
    {
        return DB::table('products')
            ->where('id', $productId)
            ->update(['channel_product_id' => $channelProductId]);
    }
}
