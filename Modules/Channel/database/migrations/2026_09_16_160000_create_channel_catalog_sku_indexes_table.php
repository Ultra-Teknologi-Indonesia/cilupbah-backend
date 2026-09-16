<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_catalog_sku_indexes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('channel_shop_id');
            $table->string('external_product_id', 255);
            $table->string('external_sku_id', 255)->nullable();
            $table->string('seller_sku', 255);
            $table->string('normalized_seller_sku', 255);
            $table->string('product_name', 500)->nullable();
            $table->string('listing_status', 50)->nullable();
            $table->timestampTz('last_seen_at');
            $table->timestampsTz();

            $table->foreign('channel_shop_id')
                ->references('id')
                ->on('channel_shops')
                ->cascadeOnDelete();

            $table->unique(
                ['channel_shop_id', 'external_product_id', 'normalized_seller_sku'],
                'channel_catalog_sku_indexes_listing_sku_unique'
            );
            $table->index(
                ['channel_shop_id', 'normalized_seller_sku'],
                'channel_catalog_sku_indexes_shop_sku_index'
            );
            $table->index(
                ['channel_shop_id', 'last_seen_at'],
                'channel_catalog_sku_indexes_shop_seen_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_catalog_sku_indexes');
    }
};
