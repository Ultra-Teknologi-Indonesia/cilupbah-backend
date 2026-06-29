<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('sales_orders', 'handed_to_warehouse_at')) {
                $table->timestamp('handed_to_warehouse_at')->nullable()->after('received_date');
                $table->index('handed_to_warehouse_at', 'sales_orders_handed_to_warehouse_at_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            if (Schema::hasColumn('sales_orders', 'handed_to_warehouse_at')) {
                $table->dropIndex('sales_orders_handed_to_warehouse_at_idx');
                $table->dropColumn('handed_to_warehouse_at');
            }
        });
    }
};
