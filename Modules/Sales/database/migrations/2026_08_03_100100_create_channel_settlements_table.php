<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Header pencairan/statement marketplace (arsip lengkap + rekonsiliasi).
     * TikTok: 1 statement/hari; Shopee: batch escrow_list per release_time; Lazada: statement.
     * Anti-double: UNIQUE (channel, shop_id, external_id) → upsert via updateOrCreate.
     */
    public function up(): void
    {
        Schema::create('channel_settlements', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('channel', 30);                 // shopee|tiktok|lazada
            $table->string('shop_id');                     // = sales_orders.channel_shop_id
            $table->string('external_id');                 // statement_id / batch escrow / statement lazada
            $table->string('external_payment_id')->nullable(); // payment_id (TikTok Get Payments)
            $table->string('type', 20)->default('STATEMENT');  // STATEMENT | PAYOUT

            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->timestamp('settled_at')->nullable();   // statement_time / escrow_release_time
            $table->timestamp('paid_at')->nullable();      // paid_time / payout time

            $table->string('payment_status', 20)->nullable(); // PAID | PROCESSING | FAILED

            $table->decimal('total_settlement', 18, 4)->nullable();
            $table->decimal('total_fee', 18, 4)->nullable();
            $table->decimal('total_adjustment', 18, 4)->nullable();
            $table->string('currency', 8)->default('IDR');

            $table->jsonb('raw')->nullable();              // response mentah (arsip penuh)
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['channel', 'shop_id', 'external_id']);
            $table->index(['channel', 'settled_at']);
            $table->index('payment_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_settlements');
    }
};
