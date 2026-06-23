<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->string('fulfillment_type')->nullable()->after('channel_fulfillment_status');
            $table->string('delivery_option_id')->nullable()->after('fulfillment_type');
            $table->string('shipping_type')->nullable()->after('delivery_option_id');
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropColumn(['fulfillment_type', 'delivery_option_id', 'shipping_type']);
        });
    }
};
