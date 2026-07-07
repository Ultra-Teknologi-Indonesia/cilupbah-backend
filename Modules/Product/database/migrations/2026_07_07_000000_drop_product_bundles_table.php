<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel legacy `product_bundles` (variant→variant) sudah dimigrasikan ke
 * `product_bundle_items` (product→variant) oleh migrasi 2026_06_16_120000.
 * Model ProductBundle & relasi ProductVariant::bundleComponents() sudah dihapus,
 * jadi tabel ini benar-benar tidak terpakai lagi. `down()` merekonstruksi struktur
 * asli agar migrasi tetap reversibel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('product_bundles');
    }

    public function down(): void
    {
        if (Schema::hasTable('product_bundles')) {
            return;
        }

        Schema::create('product_bundles', function (Blueprint $table) {
            $table->id();
            $table->uuid('bundle_variant_id');
            $table->uuid('component_variant_id');
            $table->foreign('bundle_variant_id')->references('id')->on('product_variants')->onDelete('cascade');
            $table->foreign('component_variant_id')->references('id')->on('product_variants')->onDelete('cascade');
            $table->integer('qty')->default(1);
            $table->timestamps();
        });
    }
};
