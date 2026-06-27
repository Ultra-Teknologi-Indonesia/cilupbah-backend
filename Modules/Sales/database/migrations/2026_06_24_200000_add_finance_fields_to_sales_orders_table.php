<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {

            $table->decimal('seller_voucher', 18, 4)->nullable()->after('total_disc');
            $table->decimal('platform_voucher', 18, 4)->nullable()->after('seller_voucher');

            $table->decimal('commission_fee', 18, 4)->nullable()->after('platform_voucher');
            $table->decimal('service_fee', 18, 4)->nullable()->after('commission_fee');
            $table->decimal('transaction_fee', 18, 4)->nullable()->after('service_fee');
            $table->decimal('affiliate_commission', 18, 4)->nullable()->after('transaction_fee');

            $table->decimal('seller_shipping_borne', 18, 4)->nullable()->after('affiliate_commission');
            $table->decimal('platform_shipping_rebate', 18, 4)->nullable()->after('seller_shipping_borne');

            $table->decimal('settlement_amount', 18, 4)->nullable()->after('platform_shipping_rebate');

            $table->string('fee_currency', 8)->default('IDR')->after('settlement_amount');

            $table->boolean('is_settled')->default(false)->after('fee_currency');
            $table->timestamp('finance_synced_at')->nullable()->after('is_settled');

            $table->index('is_settled');
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropIndex(['is_settled']);
            $table->dropColumn([
                'seller_voucher',
                'platform_voucher',
                'commission_fee',
                'service_fee',
                'transaction_fee',
                'affiliate_commission',
                'seller_shipping_borne',
                'platform_shipping_rebate',
                'settlement_amount',
                'fee_currency',
                'is_settled',
                'finance_synced_at',
            ]);
        });
    }
};
