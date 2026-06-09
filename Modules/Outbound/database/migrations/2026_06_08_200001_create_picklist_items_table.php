<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('picklist_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('picklist_id');
            $table->foreign('picklist_id')->references('id')->on('picklists')->cascadeOnDelete();
            $table->uuid('order_id');
            $table->foreign('order_id')->references('id')->on('orders')->restrictOnDelete();
            $table->uuid('order_item_id');
            $table->foreign('order_item_id')->references('id')->on('order_items')->restrictOnDelete();
            $table->uuid('item_id');
            $table->foreign('item_id')->references('id')->on('product_variants')->restrictOnDelete();
            $table->string('sku', 100);
            $table->uuid('bin_id')->nullable();
            $table->foreign('bin_id')->references('id')->on('location_bins')->nullOnDelete();
            $table->integer('qty_ordered');
            $table->integer('qty_picked')->default(0);
            $table->timestamps();

            $table->index('picklist_id');
            $table->index('order_id');
            $table->index('item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('picklist_items');
    }
};
