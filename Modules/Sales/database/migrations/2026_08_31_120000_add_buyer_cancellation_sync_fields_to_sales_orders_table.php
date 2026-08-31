<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->string('buyer_cancel_sync_status', 20)->nullable()->after('cancel_reject_reason');
            $table->string('buyer_cancel_sync_decision', 10)->nullable()->after('buyer_cancel_sync_status');
            $table->string('buyer_cancel_sync_error', 255)->nullable()->after('buyer_cancel_sync_decision');
            $table->timestamp('buyer_cancel_synced_at')->nullable()->after('buyer_cancel_sync_error');
            $table->string('buyer_cancel_channel_reference', 100)->nullable()->after('buyer_cancel_synced_at');
            $table->index(['source', 'buyer_cancel_sync_status'], 'sales_orders_buyer_cancel_sync_idx');
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropIndex('sales_orders_buyer_cancel_sync_idx');
            $table->dropColumn([
                'buyer_cancel_sync_status',
                'buyer_cancel_sync_decision',
                'buyer_cancel_sync_error',
                'buyer_cancel_synced_at',
                'buyer_cancel_channel_reference',
            ]);
        });
    }
};
