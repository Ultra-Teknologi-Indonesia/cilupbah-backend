<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_bundle_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('bundle_product_id');     // produk bundle (products.id, is_bundle=true)
            $table->uuid('component_variant_id');  // komponen (product_variants.id)
            $table->integer('qty')->default(1);
            $table->timestamps();

            $table->index('bundle_product_id');
            $table->index('component_variant_id');
            $table->unique(['bundle_product_id', 'component_variant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_bundle_items');
    }
};
