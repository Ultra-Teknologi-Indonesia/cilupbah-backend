<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('packlist_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('packlist_id');
            $table->foreign('packlist_id')->references('id')->on('packlists')->cascadeOnDelete();
            $table->uuid('order_item_id');
            $table->foreign('order_item_id')->references('id')->on('order_items')->restrictOnDelete();
            $table->uuid('item_id');
            $table->foreign('item_id')->references('id')->on('product_variants')->restrictOnDelete();
            $table->string('sku', 100);
            $table->integer('qty_ordered');
            $table->integer('qty_packed')->default(0);
            $table->boolean('barcode_verified')->default(false);
            $table->timestamps();

            $table->index('packlist_id');
            $table->index('item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('packlist_items');
    }
};
