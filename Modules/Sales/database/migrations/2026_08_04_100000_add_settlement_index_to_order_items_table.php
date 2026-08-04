<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::table('sales_order_items', function (Blueprint $table) {
            $table->index(['order_id', 'item_id'], 'sales_order_items_order_id_item_id_idx');
        });
    }

    public function down(): void
    {
        Schema::table('sales_order_items', function (Blueprint $table) {
            $table->dropIndex('sales_order_items_order_id_item_id_idx');
        });
    }
};
