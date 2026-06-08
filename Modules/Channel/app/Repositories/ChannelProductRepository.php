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
        return DB::table('variant_options')
            ->leftJoin('attributes', 'attributes.id', '=', 'variant_options.attribute_id')
            ->where('variant_options.variant_id', $variantId)
            ->select('variant_options.*', 'attributes.name as attribute_name')
            ->get()
            ->toArray();
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
     * Mengembalikan id baris (UUID) agar caller bisa membuat variant mappings.
     */
    public function upsertChannelMapping(
        string $productId,
        string $shopId,
        ?string $externalProductId = null,
        string $syncStatus = 'synced'
    ): string {
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

            return $existing->id;
        }

        $pcmId = Uuid::uuid7()->getHex()->toString();
        DB::table('product_channel_mappings')->insert([
            'id'                  => $pcmId,
            'product_id'          => $productId,
            'channel_shop_id'     => $channelShop->id,
            'external_product_id' => $externalProductId,
            'sync_status'         => $syncStatus,
            'last_synced_at'      => $now,
            'created_at'          => $now,
            'updated_at'          => $now,
        ]);

        return $pcmId;
    }

    /**
     * Upsert satu baris product_variant_channel_mappings.
     * Menyimpan external_sku_id TikTok agar webhook stock-sync bisa bekerja.
     */
    public function upsertVariantChannelMapping(
        string $pcmId,
        string $variantId,
        ?string $externalSkuId = null
    ): void {
        $now = now();

        $existing = DB::table('product_variant_channel_mappings')
            ->where('product_channel_mapping_id', $pcmId)
            ->where('variant_id', $variantId)
            ->first();

        if ($existing) {
            $update = ['updated_at' => $now];
            if ($externalSkuId !== null) {
                $update['external_sku_id'] = $externalSkuId;
            }
            DB::table('product_variant_channel_mappings')
                ->where('id', $existing->id)
                ->update($update);
            return;
        }

        DB::table('product_variant_channel_mappings')->insert([
            'id'                         => Uuid::uuid7()->getHex()->toString(),
            'product_channel_mapping_id' => $pcmId,
            'variant_id'                 => $variantId,
            'external_sku_id'            => $externalSkuId,
            'created_at'                 => $now,
            'updated_at'                 => $now,
        ]);
    }
}
