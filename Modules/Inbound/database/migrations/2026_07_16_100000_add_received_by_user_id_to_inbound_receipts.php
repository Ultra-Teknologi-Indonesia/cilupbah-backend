<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inbound_receipts', function (Blueprint $table) {
            $table->uuid('received_by_user_id')->nullable()->after('received_by');
            $table->foreign('received_by_user_id')
                ->references('id')
                ->on('users')
                ->onDelete('restrict');
            $table->index(['inbound_item_id', 'received_date'], 'idx_inbound_receipts_item_date');
            $table->index(['inbound_item_id', 'received_by_user_id'], 'idx_inbound_receipts_item_user');
        });
    }

    public function down(): void
    {
        Schema::table('inbound_receipts', function (Blueprint $table) {
            $table->dropIndex('idx_inbound_receipts_item_user');
            $table->dropIndex('idx_inbound_receipts_item_date');
            $table->dropForeign(['received_by_user_id']);
            $table->dropColumn('received_by_user_id');
        });
    }
};
