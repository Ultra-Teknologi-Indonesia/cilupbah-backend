<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            // Tanggal cair aktual dari marketplace (BUKAN finance_synced_at kita).
            $table->timestamp('settled_at')->nullable()->after('finance_synced_at');

            // Nilai retur/refund yang mengurangi settlement (Shopee seller_return_refund,
            // TikTok *_refund_amount, Lazada koreksi). FE order-detail-view sudah membacanya.
            $table->decimal('refund_total', 18, 4)->nullable()->after('settlement_amount');

            // Gross/omzet kotor sebelum diskon (opsional; kalau kosong FE turunkan dari grand_total).
            $table->decimal('gross_amount', 18, 4)->nullable()->after('refund_total');

            // Link ke header pencairan (statement/payout) marketplace.
            $table->uuid('channel_settlement_id')->nullable()->after('settled_at');

            // Payload finance mentah per pesanan → jaminan "lengkap per pesanan"
            // (tak ada field channel yang hilang walau belum dipetakan ke kolom).
            $table->jsonb('finance_raw')->nullable();

            $table->index('settled_at');
            $table->index('channel_settlement_id');
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropIndex(['settled_at']);
            $table->dropIndex(['channel_settlement_id']);
            $table->dropColumn([
                'settled_at',
                'refund_total',
                'gross_amount',
                'channel_settlement_id',
                'finance_raw',
            ]);
        });
    }
};
