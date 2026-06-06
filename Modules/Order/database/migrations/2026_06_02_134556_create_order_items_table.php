<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->uuid('item_id')->nullable();
            $table->string('channel_product_id')->nullable();
            $table->string('sku')->nullable();
            $table->string('description')->nullable();
            $table->integer('qty_in_base')->default(1);
            $table->decimal('price', 12, 2)->default(0);
            $table->decimal('disc', 12, 2)->default(0);
            $table->decimal('disc_amount', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('amount', 12, 2)->default(0);
            
            $table->string('shipper')->nullable();
            $table->string('tracking_no')->nullable();
            
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
