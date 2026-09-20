<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_stock_sync_outbox', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('product_channel_mapping_id')->unique();
            $table->uuid('product_id');
            $table->uuid('channel_shop_id');
            $table->boolean('sync_stock')->default(false);
            $table->boolean('sync_price')->default(false);
            $table->string('queue_tier', 20)->default('critical');
            $table->string('status', 20)->default('pending');
            $table->unsignedBigInteger('requested_version')->default(1);
            $table->unsignedBigInteger('dispatched_version')->default(0);
            $table->unsignedBigInteger('completed_version')->default(0);
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestampTz('next_attempt_at')->nullable();
            $table->timestampTz('lease_expires_at')->nullable();
            $table->timestampTz('dispatched_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestampsTz();

            $table->foreign('product_channel_mapping_id', 'channel_stock_outbox_mapping_fk')
                ->references('id')
                ->on('product_channel_mappings')
                ->cascadeOnDelete();
            $table->foreign('product_id', 'channel_stock_outbox_product_fk')
                ->references('id')
                ->on('products')
                ->cascadeOnDelete();
            $table->foreign('channel_shop_id', 'channel_stock_outbox_shop_fk')
                ->references('id')
                ->on('channel_shops')
                ->cascadeOnDelete();

            $table->index(['status', 'next_attempt_at'], 'channel_stock_outbox_due_idx');
            $table->index(['channel_shop_id', 'status'], 'channel_stock_outbox_shop_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_stock_sync_outbox');
    }
};
