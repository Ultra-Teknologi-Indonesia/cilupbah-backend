<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_order_status_histories', function (Blueprint $table) {
            $table->string('entity_type', 16)->default('ORDER')->after('salesorder_id');
            $table->uuid('entity_id')->nullable()->after('entity_type');
            $table->index(['salesorder_id', 'action_id', 'created_at'], 'so_hist_action_cursor_idx');
        });
    }

    public function down(): void
    {
        Schema::table('sales_order_status_histories', function (Blueprint $table) {
            $table->dropIndex('so_hist_action_cursor_idx');
            $table->dropColumn(['entity_type', 'entity_id']);
        });
    }
};
