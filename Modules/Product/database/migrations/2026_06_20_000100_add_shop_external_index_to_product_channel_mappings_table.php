<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_channel_mappings', function (Blueprint $table) {
            // Mempercepat lookup "sudah diunduh?" (flagDownloaded) dan upsert mapping
            // saat download: WHERE channel_shop_id = ? AND external_product_id IN/= ?.
            // Postgres tidak membuat index FK otomatis, jadi tambahkan composite.
            $table->index(['channel_shop_id', 'external_product_id'], 'pcm_shop_external_idx');
        });
    }

    public function down(): void
    {
        Schema::table('product_channel_mappings', function (Blueprint $table) {
            $table->dropIndex('pcm_shop_external_idx');
        });
    }
};
