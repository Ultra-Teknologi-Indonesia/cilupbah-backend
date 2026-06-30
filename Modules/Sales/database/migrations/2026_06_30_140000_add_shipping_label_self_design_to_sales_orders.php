<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {

        if (Schema::hasColumn('sales_orders', 'shipping_label_status')) {

            $driver = Schema::getConnection()->getDriverName();
            if ($driver === 'mysql' || $driver === 'mariadb') {
                DB::statement("ALTER TABLE sales_orders MODIFY shipping_label_status VARCHAR(32) NULL");
            } else {

            }
        }

        Schema::table('sales_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('sales_orders', 'shipping_label_raw_data')) {
                $table->json('shipping_label_raw_data')
                    ->nullable()
                    ->after('shipping_label_prepared_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            if (Schema::hasColumn('sales_orders', 'shipping_label_raw_data')) {
                $table->dropColumn('shipping_label_raw_data');
            }
        });

    }
};
