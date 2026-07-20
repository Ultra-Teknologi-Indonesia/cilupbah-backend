<?php

namespace Modules\Channel\Tests\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Sejak kebijakan "hanya terima pesanan untuk SKU yang sudah diunduh" berlaku,
 * fixture pull/webhook channel wajib menyemai varian lebih dulu — kalau tidak,
 * pesanannya ditolak di ingest dan test menguji hal yang salah.
 */
trait SeedsCatalogVariant
{
    protected function seedCatalogVariant(string $sku): string
    {
        $existing = DB::table('product_variants')->where('sku', $sku)->value('id');
        if ($existing) {
            return $existing;
        }

        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'Kategori ' . $sku,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $productId = Str::uuid()->toString();
        DB::table('products')->insert([
            'id' => $productId,
            'category_id' => $categoryId,
            'name' => 'Produk ' . $sku,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $variantId = Str::uuid()->toString();
        DB::table('product_variants')->insert([
            'id' => $variantId,
            'product_id' => $productId,
            'sku' => $sku,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $variantId;
    }
}
