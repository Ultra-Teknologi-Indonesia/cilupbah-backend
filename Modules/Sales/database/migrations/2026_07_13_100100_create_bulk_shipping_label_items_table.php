<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bulk_shipping_label_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('batch_id');
            $table->uuid('order_id');
            $table->string('channel', 32);
            $table->enum('status', [
                'pending',
                'downloading',
                'waiting_shopee_prep',
                'done',
                'failed',
            ])->default('pending');
            $table->string('reason')->nullable();
            $table->binary('pdf_bytes')->nullable();
            $table->timestamp('downloaded_at')->nullable();
            $table->timestamps();

            $table->foreign('batch_id')->references('id')->on('bulk_shipping_label_batches')->cascadeOnDelete();
            $table->foreign('order_id')->references('id')->on('sales_orders')->cascadeOnDelete();
            $table->index(['batch_id', 'status']);
            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bulk_shipping_label_items');
    }
};
