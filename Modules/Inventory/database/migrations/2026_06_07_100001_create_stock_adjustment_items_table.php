<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_adjustment_items', function (Blueprint $table) {
            $table->string('id', 32)->primary();
            $table->string('stock_adjustment_id', 32);
            $table->foreign('stock_adjustment_id')->references('id')->on('stock_adjustments')->cascadeOnDelete();
            $table->string('item_id', 32);
            $table->foreign('item_id')->references('id')->on('product_variants')->restrictOnDelete();
            $table->string('bin_id', 32)->nullable();
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
