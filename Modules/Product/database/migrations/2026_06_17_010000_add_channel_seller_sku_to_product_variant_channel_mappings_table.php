<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variant_channel_mappings', function (Blueprint $table) {
            // Seller SKU aktual di channel (dari pull/webhook). Untuk deteksi
            // "SKU Tidak Seragam" terhadap SKU master.
            $table->string('channel_seller_sku')->nullable()->after('external_sku_id');
        });
    }

    public function down(): void
    {
        Schema::table('product_variant_channel_mappings', function (Blueprint $table) {
            $table->dropColumn('channel_seller_sku');
        });
    }
};
