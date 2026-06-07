<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('putaway_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('putaway_id');
            $table->foreign('putaway_id')->references('id')->on('putaways')->cascadeOnDelete();
            $table->uuid('item_id');
            $table->foreign('item_id')->references('id')->on('product_variants')->restrictOnDelete();
            $table->uuid('source_bin_id');
            $table->foreign('source_bin_id')->references('id')->on('location_bins')->restrictOnDelete();
            $table->uuid('destination_bin_id')->nullable();
            $table->foreign('destination_bin_id')->references('id')->on('location_bins')->nullOnDelete();
            $table->integer('qty');
            $table->integer('putaway_qty')->default(0);
            $table->string('batch_no', 100)->nullable();
            $table->string('serial_no', 100)->nullable();
            $table->timestamps();

            $table->index('putaway_id');
            $table->index('item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('putaway_items');
    }
};
