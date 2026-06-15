<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::create('variant_unlimited_shops', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('variant_id');
            $table->uuid('channel_shop_id');
            $table->timestamps();

            $table->foreign('variant_id')->references('id')->on('product_variants')->cascadeOnDelete();
            $table->foreign('channel_shop_id')->references('id')->on('channel_shops')->cascadeOnDelete();
            $table->unique(['variant_id', 'channel_shop_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('variant_unlimited_shops');
    }
};
