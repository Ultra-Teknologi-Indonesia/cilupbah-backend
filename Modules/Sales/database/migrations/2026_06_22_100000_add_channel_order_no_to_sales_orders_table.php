<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->string('channel_order_no')->nullable()->after('salesorder_no');
            $table->unsignedBigInteger('so_sequence')->nullable()->after('channel_order_no');

            $table->index('channel_order_no');
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropIndex(['channel_order_no']);
            $table->dropColumn(['channel_order_no', 'so_sequence']);
        });
    }
};
