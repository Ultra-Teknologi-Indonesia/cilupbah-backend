<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->timestamp('cancel_accepted_at')->nullable()->after('cancel_by');
            $table->string('cancel_accepted_by', 36)->nullable()->after('cancel_accepted_at');
            $table->string('cancel_channel', 10)->nullable()->after('cancel_accepted_by');
            $table->timestamp('cancel_rejected_at')->nullable()->after('cancel_channel');
            $table->string('cancel_rejected_by', 36)->nullable()->after('cancel_rejected_at');
            $table->string('cancel_reject_reason', 255)->nullable()->after('cancel_rejected_by');

            $table->index(['is_canceled', 'handed_to_warehouse_at'], 'idx_so_cancel_post_pack');
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropIndex('idx_so_cancel_post_pack');
            $table->dropColumn([
                'cancel_accepted_at',
                'cancel_accepted_by',
                'cancel_channel',
                'cancel_rejected_at',
                'cancel_rejected_by',
                'cancel_reject_reason',
            ]);
        });
    }
};
