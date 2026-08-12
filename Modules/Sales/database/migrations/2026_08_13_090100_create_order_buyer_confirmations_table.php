<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_buyer_confirmations', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('order_id');
            $table->foreign('order_id')
                ->references('id')->on('sales_orders')
                ->cascadeOnDelete();

            $table->uuid('order_item_id')->nullable();
            $table->foreign('order_item_id')
                ->references('id')->on('sales_order_items')
                ->nullOnDelete();

            $table->uuid('item_id')->nullable();
            $table->foreign('item_id')
                ->references('id')->on('product_variants')
                ->nullOnDelete();

            $table->unsignedInteger('qty_short')->default(0);

            $table->string('outcome', 16)->nullable();

            $table->uuid('replacement_item_id')->nullable();
            $table->foreign('replacement_item_id')
                ->references('id')->on('product_variants')
                ->nullOnDelete();

            $table->text('note')->nullable();

            $table->uuid('raised_by')->nullable();
            $table->foreign('raised_by')
                ->references('id')->on('users')
                ->nullOnDelete();

            $table->timestamp('raised_at');

            $table->uuid('confirmed_by')->nullable();
            $table->foreign('confirmed_by')
                ->references('id')->on('users')
                ->nullOnDelete();

            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('resolved_at')->nullable();

            $table->timestamps();

            $table->index('order_id');
            $table->index(['order_id', 'resolved_at']);
            $table->index(['outcome', 'resolved_at']);
            $table->index(['item_id', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_buyer_confirmations');
    }
};
