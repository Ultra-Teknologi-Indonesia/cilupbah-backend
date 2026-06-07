<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reserved_stock_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('reserved_stock_id');
            $table->foreign('reserved_stock_id')->references('id')->on('reserved_stocks')->cascadeOnDelete();
            $table->uuid('item_id');
            $table->foreign('item_id')->references('id')->on('product_variants')->restrictOnDelete();
            $table->uuid('bin_id')->nullable();
            $table->foreign('bin_id')->references('id')->on('location_bins')->nullOnDelete();
            $table->integer('qty');
            $table->timestamps();

            $table->index('item_id');
            $table->index('reserved_stock_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reserved_stock_items');
    }
};
