<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_merges', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // 1 produk hanya boleh menempel ke 1 master (paritas local_product_merge.sku di cilupbah-ops)
            $table->uuid('product_id')->unique();
            $table->string('master_name')->index();
            $table->timestamps();

            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_merges');
    }
};
