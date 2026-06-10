<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_opname_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('stock_opname_id');
            $table->foreign('stock_opname_id')->references('id')->on('stock_opnames')->cascadeOnDelete();
            $table->uuid('item_id');
            $table->foreign('item_id')->references('id')->on('product_variants')->restrictOnDelete();
            $table->uuid('bin_id')->nullable();
            $table->foreign('bin_id')->references('id')->on('location_bins')->nullOnDelete();
            $table->string('batch_no', 100)->nullable();
            $table->string('serial_no', 100)->nullable();
            $table->dateTime('expired_date')->nullable();
            $table->integer('qty_system')->default(0);
            $table->integer('qty_actual')->nullable();
            $table->integer('qty_difference')->nullable();
            $table->string('reason', 255)->nullable();
            $table->string('counted_by', 100)->nullable();
            $table->dateTime('counted_at')->nullable();
            $table->timestamps();

            $table->index('stock_opname_id');
            $table->index('item_id');
            $table->index('bin_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_opname_items');
    }
};
