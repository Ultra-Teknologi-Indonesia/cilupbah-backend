<?php

namespace Modules\Channel\Repositories;

use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

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

    /**
     * Produk yang BELUM punya mapping ke toko/channel tertentu ("Belum Upload").
     * Menggantikan query lama `whereNull('channel_product_id')` yang kolomnya sudah di-drop.
     */
    public function getUnsyncedProducts(string $shopId)
    {
        $channelShop = DB::table('channel_shops')->where('shop_id', $shopId)->first();

        $mappedProductIds = $channelShop
            ? DB::table('product_channel_mappings')
                ->where('channel_shop_id', $channelShop->id)
                ->pluck('product_id')
            : collect();

        return DB::table('products')
            ->whereNotIn('id', $mappedProductIds)
            ->get();
    }

    public function getVariantBySku(string $sku)
    {
        return DB::table('product_variants')->where('sku', $sku)->first();
    }

    public function findById(string $id)
    {
        return DB::table('products')->where('id', $id)->first();
    }

    public function getVariantsByProductId(string $productId)
    {
        return DB::table('product_variants')->where('product_id', $productId)->get();
    }

    public function getMediaByProductId(string $productId)
    {
        return DB::table('product_media')->where('product_id', $productId)->get();
    }

    public function getVariantOptions(string $variantId)
    {
        return DB::table('variant_options')->where('variant_id', $variantId)->get()->toArray();
    }

    /**
     * Ambil external_product_id (ID produk di marketplace) dari tabel pivot
     * berdasarkan product_id internal + shop_id marketplace.
     */
    public function getExternalProductId(string $productId, string $shopId): ?string
    {
        $channelShop = DB::table('channel_shops')->where('shop_id', $shopId)->first();
        if (!$channelShop) {
            return null;
        }

        $mapping = DB::table('product_channel_mappings')
            ->where('product_id', $productId)
            ->where('channel_shop_id', $channelShop->id)
            ->first();

        return $mapping->external_product_id ?? null;
    }

    /**
     * Simpan/perbarui mapping produk↔channel + external_product_id.
     * Menggantikan update kolom lama `products.channel_product_id` yang sudah di-drop.
     */
    public function updateChannelProductId(string $productId, string $channelProductId, string $shopId): void
    {
        $this->upsertChannelMapping($productId, $shopId, $channelProductId, 'synced');
    }

    /**
     * Upsert satu baris product_channel_mappings.
     */
    public function upsertChannelMapping(
        string $productId,
        string $shopId,
        ?string $externalProductId = null,
        string $syncStatus = 'synced'
    ): void {
        $channelShop = DB::table('channel_shops')->where('shop_id', $shopId)->first();
        if (!$channelShop) {
            throw new \Exception("Channel shop tidak ditemukan untuk shop_id: {$shopId}");
        }

        $now = now();

        $existing = DB::table('product_channel_mappings')
            ->where('product_id', $productId)
            ->where('channel_shop_id', $channelShop->id)
            ->first();

        if ($existing) {
            $update = [
                'sync_status'    => $syncStatus,
                'last_synced_at' => $now,
                'updated_at'     => $now,
            ];
            // Hanya timpa external_product_id jika nilainya disediakan.
            if ($externalProductId !== null) {
                $update['external_product_id'] = $externalProductId;
            }

            DB::table('product_channel_mappings')
                ->where('id', $existing->id)
                ->update($update);

            return;
        }

        DB::table('product_channel_mappings')->insert([
            'id'                  => Uuid::uuid7()->getHex()->toString(),
            'product_id'          => $productId,
            'channel_shop_id'     => $channelShop->id,
            'external_product_id' => $externalProductId,
            'sync_status'         => $syncStatus,
            'last_synced_at'      => $now,
            'created_at'          => $now,
            'updated_at'          => $now,
        ]);
    }
}
