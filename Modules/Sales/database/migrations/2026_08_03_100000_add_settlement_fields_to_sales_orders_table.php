<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {

            $table->timestamp('settled_at')->nullable()->after('finance_synced_at');

            $table->decimal('refund_total', 18, 4)->nullable()->after('settlement_amount');

            $table->decimal('gross_amount', 18, 4)->nullable()->after('refund_total');

            $table->uuid('channel_settlement_id')->nullable()->after('settled_at');

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
