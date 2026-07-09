<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_returns', function (Blueprint $table) {
            // Keputusan marketplace (hasil sync detail dari API channel), terpisah dari
            // `status` lokal yang merepresentasikan progres gudang (PENDING/ACCEPTED/dst).
            $table->string('marketplace_decision', 30)->nullable()->after('status');
            $table->timestamp('marketplace_decision_at')->nullable()->after('marketplace_decision');
            $table->string('marketplace_raw_status', 100)->nullable()->after('marketplace_decision_at');

            // Alasan retur versi channel, terpisah dari `reason` (bisa berbeda isi/bahasa).
            $table->string('channel_reason_code', 100)->nullable()->after('marketplace_raw_status');
            $table->text('channel_reason_text')->nullable()->after('channel_reason_code');

            // Finansial retur, ditarik dari API detail retur channel.
            $table->decimal('refund_amount', 15, 2)->nullable()->after('channel_reason_text');
            $table->string('refund_currency', 5)->nullable()->default('IDR')->after('refund_amount');
            $table->decimal('shipping_fee_original', 15, 2)->nullable()->after('refund_currency');
            $table->decimal('shipping_fee_return', 15, 2)->nullable()->after('shipping_fee_original');

            // Kapan fetchReturnDetail terakhir dijalankan untuk retur ini (terpisah dari
            // tracking_synced_at yang hanya menandai sinkron resi).
            $table->timestamp('detail_synced_at')->nullable()->after('shipping_fee_return');

            $table->index('marketplace_decision', 'sales_returns_marketplace_decision_index');
        });
    }

    public function down(): void
    {
        Schema::table('sales_returns', function (Blueprint $table) {
            $table->dropIndex('sales_returns_marketplace_decision_index');
            $table->dropColumn([
                'marketplace_decision',
                'marketplace_decision_at',
                'marketplace_raw_status',
                'channel_reason_code',
                'channel_reason_text',
                'refund_amount',
                'refund_currency',
                'shipping_fee_original',
                'shipping_fee_return',
                'detail_synced_at',
            ]);
        });
    }
};
