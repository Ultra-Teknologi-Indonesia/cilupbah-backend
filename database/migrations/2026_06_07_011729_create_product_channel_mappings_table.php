<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_channel_mappings', function (Blueprint $table) {
            $table->string('id', 32)->primary();
            $table->string('product_id', 32);
            $table->string('channel_shop_id', 32);
            $table->string('external_product_id')->nullable()->comment('ID produk di marketplace');
            $table->enum('sync_status', [
                'pending',
                'syncing',
                'synced',
                'failed',
                'deactivated',
            ])->default('pending');
            $table->text('error_message')->nullable()->comment('Pesan error dari marketplace');
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
            $table->foreign('channel_shop_id')->references('id')->on('channel_shops')->onDelete('cascade');
            $table->unique(['product_id', 'channel_shop_id'], 'pcm_product_shop_unique');
            $table->index('sync_status');
            $table->index('external_product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_channel_mappings');
    }
};
