<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_channel_drafts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('product_id');
            $table->uuid('channel_shop_id');
            $table->string('channel_category_id')->nullable()->comment('ID kategori di channel');
            $table->json('attribute_mapping')->nullable()->comment('Mapping atribut produk -> channel');
            $table->decimal('price_override', 15, 2)->nullable();
            $table->enum('status', ['draft', 'ready', 'cancelled'])->default('draft');
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->foreign('channel_shop_id')->references('id')->on('channel_shops')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->unique(['product_id', 'channel_shop_id'], 'pcd_product_shop_unique');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_channel_drafts');
    }
};
