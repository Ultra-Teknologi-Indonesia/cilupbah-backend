<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_adjustment_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('stock_adjustment_id');
            $table->foreign('stock_adjustment_id')->references('id')->on('stock_adjustments')->cascadeOnDelete();
            $table->uuid('item_id');
            $table->foreign('item_id')->references('id')->on('product_variants')->restrictOnDelete();
            $table->uuid('bin_id')->nullable();
            $table->foreign('bin_id')->references('id')->on('location_bins')->nullOnDelete();
            $table->string('batch_no', 100)->nullable();
            $table->string('serial_no', 100)->nullable();
            $table->integer('system_qty');
            $table->integer('actual_qty');
            $table->integer('difference_qty');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('item_id');
            $table->index('stock_adjustment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_adjustment_items');
    }
};
