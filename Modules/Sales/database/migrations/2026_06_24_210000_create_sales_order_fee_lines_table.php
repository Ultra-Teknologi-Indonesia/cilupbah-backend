<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_order_fee_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('order_id');

            $table->string('fee_type', 50);

            $table->string('channel_fee_code', 80)->nullable();
            $table->decimal('amount', 18, 4)->default(0);
            $table->string('source', 20);
            $table->boolean('is_settled')->default(false);
            $table->timestamps();

            $table->foreign('order_id')->references('id')->on('sales_orders')->cascadeOnDelete();
            $table->index('order_id');
            $table->index('fee_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_order_fee_lines');
    }
};
