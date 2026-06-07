<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variant_channel_mappings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('product_channel_mapping_id');
            $table->uuid('variant_id');
            $table->string('external_sku_id')->nullable()->comment('ID SKU di marketplace');
            $table->decimal('synced_price', 15, 2)->nullable()->comment('Harga terakhir yang berhasil di-sync');
            $table->integer('synced_stock')->nullable()->comment('Stok terakhir yang berhasil di-sync');
            $table->timestamps();

            $table->foreign('product_channel_mapping_id', 'pvcm_pcm_fk')
                  ->references('id')->on('product_channel_mappings')->onDelete('cascade');
            $table->foreign('variant_id')
                  ->references('id')->on('product_variants')->onDelete('cascade');
            $table->unique(['product_channel_mapping_id', 'variant_id'], 'pvcm_unique');
            $table->index('external_sku_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variant_channel_mappings');
    }
};
