<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fulfillment_removals', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('order_id');
            $table->foreign('order_id')
                ->references('id')->on('sales_orders')
                ->cascadeOnDelete();

            $table->enum('stage', ['picking', 'packing', 'shipping']);

            $table->string('removed_by')->nullable();
            $table->text('reason')->nullable();
            $table->boolean('reversed_stock')->default(false);

            $table->timestamp('created_at')->nullable();

            $table->index('order_id');
            $table->index(['order_id', 'stage']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fulfillment_removals');
    }
};
