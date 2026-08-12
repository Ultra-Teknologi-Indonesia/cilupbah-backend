<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_bin_allocations', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('order_id');
            $table->foreign('order_id')
                ->references('id')->on('sales_orders')
                ->cascadeOnDelete();

            $table->uuid('order_item_id')->nullable();
            $table->foreign('order_item_id')
                ->references('id')->on('sales_order_items')
                ->nullOnDelete();

            $table->uuid('item_id');
            $table->foreign('item_id')
                ->references('id')->on('product_variants')
                ->restrictOnDelete();

            $table->uuid('location_id');
            $table->foreign('location_id')
                ->references('id')->on('locations')
                ->restrictOnDelete();

            $table->uuid('bin_id');
            $table->foreign('bin_id')
                ->references('id')->on('location_bins')
                ->restrictOnDelete();

            $table->unsignedInteger('qty');

            $table->uuid('completed_by')->nullable();
            $table->foreign('completed_by')
                ->references('id')->on('users')
                ->nullOnDelete();

            $table->timestamp('completed_at');
            $table->timestamp('reversed_at')->nullable();

            $table->uuid('reversed_by')->nullable();
            $table->foreign('reversed_by')
                ->references('id')->on('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->index('order_id');
            $table->index(['order_id', 'reversed_at']);
            $table->index(['item_id', 'bin_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_bin_allocations');
    }
};
