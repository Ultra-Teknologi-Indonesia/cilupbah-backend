<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_channel_validations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('product_id');
            $table->uuid('channel_id');

            $table->enum('attribute_status', ['ok', 'mismatch', 'na'])->default('na');
            $table->json('attribute_issues')->nullable();

            $table->enum('price_status', ['ok', 'mismatch', 'na'])->default('na');
            $table->json('price_issues')->nullable();

            $table->enum('sku_status', ['ok', 'mismatch', 'na'])->default('na');
            $table->json('sku_issues')->nullable();

            $table->timestamp('checked_at')->nullable();
            $table->timestamps();

            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->foreign('channel_id')->references('id')->on('channels')->cascadeOnDelete();
            $table->unique(['product_id', 'channel_id'], 'pcv_product_channel_unique');
            $table->index(['channel_id', 'attribute_status']);
            $table->index(['channel_id', 'price_status']);
            $table->index(['channel_id', 'sku_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_channel_validations');
    }
};
