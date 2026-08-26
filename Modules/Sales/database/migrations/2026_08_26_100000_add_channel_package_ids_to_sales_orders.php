<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('sales_orders', 'channel_package_ids')) {
            Schema::table('sales_orders', function (Blueprint $table): void {
                $table->json('channel_package_ids')->nullable()->after('channel_order_no');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('sales_orders', 'channel_package_ids')) {
            Schema::table('sales_orders', function (Blueprint $table): void {
                $table->dropColumn('channel_package_ids');
            });
        }
    }
};
