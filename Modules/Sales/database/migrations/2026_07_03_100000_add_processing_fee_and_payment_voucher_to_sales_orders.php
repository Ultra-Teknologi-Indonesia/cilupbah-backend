<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->decimal('order_processing_fee', 18, 4)->nullable()->after('affiliate_commission');
            $table->decimal('payment_voucher', 18, 4)->nullable()->after('platform_voucher');
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropColumn(['order_processing_fee', 'payment_voucher']);
        });
    }
};
