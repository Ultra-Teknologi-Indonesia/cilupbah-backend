<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('payment_method_name')->nullable()->after('payment_method');
            $table->string('tracking_number')->nullable()->after('payment_method_name');
            $table->string('shipping_provider')->nullable()->after('tracking_number');
            $table->text('buyer_message')->nullable()->after('shipping_provider');
            $table->text('seller_note')->nullable()->after('buyer_message');
            $table->timestamp('paid_time')->nullable()->after('seller_note');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'payment_method_name',
                'tracking_number',
                'shipping_provider',
                'buyer_message',
                'seller_note',
                'paid_time',
            ]);
        });
    }
};
