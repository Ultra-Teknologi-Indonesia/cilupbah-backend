<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->index('tracking_number');
        });

        Schema::table('packlist_items', function (Blueprint $table) {
            $table->index('sku');
            $table->index('order_item_id');
        });

        Schema::table('picklist_items', function (Blueprint $table) {
            $table->index('sku');
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropIndex(['tracking_number']);
        });

        Schema::table('packlist_items', function (Blueprint $table) {
            $table->dropIndex(['sku']);
            $table->dropIndex(['order_item_id']);
        });

        Schema::table('picklist_items', function (Blueprint $table) {
            $table->dropIndex(['sku']);
        });
    }
};
