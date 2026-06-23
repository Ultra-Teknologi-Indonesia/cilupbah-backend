<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->string('channel_buyer_id', 50)->nullable()->after('channel_shop_id');
            $table->decimal('actual_shipping_fee', 18, 4)->nullable()->after('shipping_cost');
            $table->boolean('actual_shipping_fee_confirmed')->default(false)->after('actual_shipping_fee');
            $table->unsignedInteger('order_weight_gram')->nullable()->after('grand_total');
            $table->string('dropshipper_name', 100)->nullable()->after('shipping_country');
            $table->string('dropshipper_phone', 30)->nullable()->after('dropshipper_name');
            $table->boolean('is_split_order')->default(false)->after('priority_fulfillment');
            $table->string('cancel_by', 20)->nullable()->after('cancel_reason');
            $table->string('fulfillment_flag', 50)->nullable()->after('channel_fulfillment_status');
            $table->unsignedSmallInteger('days_to_ship')->nullable()->after('shipping_type');
            $table->timestamp('ship_by_date')->nullable()->after('paid_time');
            $table->timestamp('pickup_done_time')->nullable()->after('ship_by_date');
            $table->timestamp('channel_updated_at')->nullable()->after('pickup_done_time');
            $table->timestamp('return_due_date')->nullable()->after('channel_updated_at');
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropColumn([
                'channel_buyer_id',
                'actual_shipping_fee',
                'actual_shipping_fee_confirmed',
                'order_weight_gram',
                'dropshipper_name',
                'dropshipper_phone',
                'is_split_order',
                'cancel_by',
                'fulfillment_flag',
                'days_to_ship',
                'ship_by_date',
                'pickup_done_time',
                'channel_updated_at',
                'return_due_date',
            ]);
        });
    }
};
