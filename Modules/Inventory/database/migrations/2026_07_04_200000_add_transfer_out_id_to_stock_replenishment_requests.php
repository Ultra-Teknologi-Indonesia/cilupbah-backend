<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_replenishment_requests', function (Blueprint $table) {
            $table->uuid('transfer_out_id')->nullable()->after('assignee_user_id');

            $table->foreign('transfer_out_id')
                ->references('id')->on('inventory_transfers')
                ->nullOnDelete();

            $table->index('transfer_out_id', 'idx_srr_transfer_out');
        });
    }

    public function down(): void
    {
        Schema::table('stock_replenishment_requests', function (Blueprint $table) {
            $table->dropForeign(['transfer_out_id']);
            $table->dropIndex('idx_srr_transfer_out');
            $table->dropColumn('transfer_out_id');
        });
    }
};
