<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('sales_orders', 'channel_fulfillment_status')) {

                $table->string('channel_fulfillment_status')->nullable()->after('channel_status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            if (Schema::hasColumn('sales_orders', 'channel_fulfillment_status')) {
                $table->dropColumn('channel_fulfillment_status');
            }
        });
    }
};
