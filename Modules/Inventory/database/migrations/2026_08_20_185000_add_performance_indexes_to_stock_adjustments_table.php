<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_adjustments', function (Blueprint $table) {
            $table->index(['deleted_at', 'transaction_date'], 'stock_adj_del_trans_date_idx');
            $table->index(['deleted_at', 'location_id', 'transaction_date'], 'stock_adj_del_loc_trans_idx');
        });
    }

    public function down(): void
    {
        Schema::table('stock_adjustments', function (Blueprint $table) {
            $table->dropIndex('stock_adj_del_trans_date_idx');
            $table->dropIndex('stock_adj_del_loc_trans_idx');
        });
    }
};
