<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('raise_products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('channel_shop_id');
            $table->uuid('created_by')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('channel_shop_id')->references('id')->on('channel_shops')->onDelete('cascade');
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->unique('channel_shop_id', 'raise_products_shop_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('raise_products');
    }
};
