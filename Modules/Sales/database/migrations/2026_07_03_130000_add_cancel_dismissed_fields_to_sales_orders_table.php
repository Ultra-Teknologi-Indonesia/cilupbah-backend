<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->timestamp('cancel_dismissed_at')->nullable()->after('cancel_reject_reason');
            $table->string('cancel_dismissed_by', 36)->nullable()->after('cancel_dismissed_at');

            $table->index(['status', 'cancel_dismissed_at', 'handed_to_warehouse_at'], 'idx_so_pre_manifest_cancel');
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropIndex('idx_so_pre_manifest_cancel');
            $table->dropColumn([
                'cancel_dismissed_at',
                'cancel_dismissed_by',
            ]);
        });
    }
};
