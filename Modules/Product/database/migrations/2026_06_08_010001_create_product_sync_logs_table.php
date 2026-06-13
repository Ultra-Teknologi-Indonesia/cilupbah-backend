<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_sync_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('product_id')->nullable();
            $table->uuid('channel_shop_id')->nullable();
            $table->enum('action', ['upload', 'download', 'sync_price', 'sync_stock', 'unlink']);
            $table->enum('status', ['success', 'failed', 'pending']);
            $table->json('payload')->nullable();
            $table->json('response')->nullable();
            $table->text('error_message')->nullable();
            $table->uuid('executed_by')->nullable();
            $table->timestamps();

            $table->foreign('product_id')->references('id')->on('products')->nullOnDelete();
            $table->foreign('channel_shop_id')->references('id')->on('channel_shops')->nullOnDelete();
            $table->foreign('executed_by')->references('id')->on('users')->nullOnDelete();

            $table->index('product_id');
            $table->index('channel_shop_id');
            $table->index(['action', 'status']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_sync_logs');
    }
};
