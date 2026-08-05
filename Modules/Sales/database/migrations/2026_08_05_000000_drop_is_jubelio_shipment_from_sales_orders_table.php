<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('sales_orders', 'is_jubelio_shipment')) {
            Schema::table('sales_orders', function (Blueprint $table) {
                $table->dropColumn('is_jubelio_shipment');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('sales_orders', 'is_jubelio_shipment')) {
            Schema::table('sales_orders', function (Blueprint $table) {
                $table->boolean('is_jubelio_shipment')->default(false)->after('delivery_method');
            });
        }
    }
};
