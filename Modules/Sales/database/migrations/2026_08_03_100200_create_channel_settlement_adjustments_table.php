<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Transaksi settlement yang TIDAK menempel ke satu pesanan:
     * TikTok CHARGE_BACK / PLATFORM_PENALTY / RESERVE / SHIPPING_FEE_COMPENSATION / OTHER_ADJUSTMENT,
     * koreksi Lazada, dsb. Tanpa tabel ini: Σ per-order ≠ payout.
     * Anti-double: UNIQUE (channel, external_transaction_id) → upsert.
     */
    public function up(): void
    {
        Schema::create('channel_settlement_adjustments', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('channel_settlement_id')->nullable();
            $table->string('channel', 30);
            $table->string('shop_id');
            $table->string('external_transaction_id');     // transaction id / adjustment id

            $table->string('type', 60)->nullable();         // CHARGE_BACK, PLATFORM_PENALTY, RESERVE, ...
            $table->uuid('order_id')->nullable();           // link ke sales_orders bila ada
            $table->string('channel_order_no')->nullable();

            $table->decimal('amount', 18, 4)->default(0);   // BER-TANDA (potongan negatif, kompensasi positif)
            $table->string('reason')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->string('currency', 8)->default('IDR');

            $table->jsonb('raw')->nullable();
            $table->timestamps();

            $table->unique(['channel', 'external_transaction_id']);
            $table->foreign('channel_settlement_id')->references('id')->on('channel_settlements')->nullOnDelete();
            $table->foreign('order_id')->references('id')->on('sales_orders')->nullOnDelete();
            $table->index('order_id');
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_settlement_adjustments');
    }
};
