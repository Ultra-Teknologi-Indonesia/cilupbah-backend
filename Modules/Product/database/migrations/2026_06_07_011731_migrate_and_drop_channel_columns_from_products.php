<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // === LANGKAH 1: Migrasi data dari kolom lama ke tabel pivot baru ===
        $products = DB::table('products')
            ->whereNotNull('channel_product_id')
            ->whereNotNull('channel_shop_id')
            ->get();

        foreach ($products as $product) {
            // Cari channel_shop record berdasarkan shop_id
            $channelShop = DB::table('channel_shops')
                ->where('shop_id', $product->channel_shop_id)
                ->first();

            if (!$channelShop) {
                continue; // Skip jika toko tidak ditemukan
            }

            // Cek apakah mapping sudah ada (hindari duplikasi)
            $exists = DB::table('product_channel_mappings')
                ->where('product_id', $product->id)
                ->where('channel_shop_id', $channelShop->id)
                ->exists();

            if ($exists) {
                continue;
            }

            $mappingId = \Ramsey\Uuid\Uuid::uuid7()->toString();

            DB::table('product_channel_mappings')->insert([
                'id' => $mappingId,
                'product_id' => $product->id,
                'channel_shop_id' => $channelShop->id,
                'external_product_id' => $product->channel_product_id,
                'sync_status' => 'synced',
                'last_synced_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Migrasi juga variant-level mappings
            $variants = DB::table('product_variants')
                ->where('product_id', $product->id)
                ->get();

            foreach ($variants as $variant) {
                DB::table('product_variant_channel_mappings')->insert([
                    'id' => \Ramsey\Uuid\Uuid::uuid7()->toString(),
                    'product_channel_mapping_id' => $mappingId,
                    'variant_id' => $variant->id,
                    'external_sku_id' => null, // Belum tersedia di kolom lama
                    'synced_price' => $variant->sell_price ?? null,
                    'synced_stock' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // === LANGKAH 2: Hapus kolom lama yang sudah tidak diperlukan ===
        Schema::table('products', function (Blueprint $table) {
            // Hapus kolom mapping lama
            if (Schema::hasColumn('products', 'channel_product_id')) {
                $table->dropColumn('channel_product_id');
            }
            if (Schema::hasColumn('products', 'channel_shop_id')) {
                $table->dropColumn('channel_shop_id');
            }
            if (Schema::hasColumn('products', 'source')) {
                $table->dropColumn('source');
            }
        });
    }

    public function down(): void
    {
        // Kembalikan kolom lama
        Schema::table('products', function (Blueprint $table) {
            $table->string('channel_product_id')->nullable();
            $table->string('channel_shop_id')->nullable();
            $table->string('source')->nullable();
        });
    }
};
