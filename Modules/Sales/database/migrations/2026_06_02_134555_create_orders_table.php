<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('salesorder_no')->unique();
            $table->string('channel_shop_id')->nullable();
            $table->string('customer_name')->nullable();
            $table->timestamp('transaction_date')->nullable();

            $table->decimal('sub_total', 12, 2)->default(0);
            $table->decimal('total_disc', 12, 2)->default(0);
            $table->decimal('total_tax', 12, 2)->default(0);
            $table->decimal('shipping_cost', 12, 2)->default(0);
            $table->decimal('insurance_cost', 12, 2)->default(0);
            $table->decimal('grand_total', 12, 2)->default(0);

            $table->string('shipping_full_name')->nullable();
            $table->string('shipping_phone')->nullable();
            $table->text('shipping_address')->nullable();
            $table->string('shipping_area')->nullable();
            $table->string('shipping_city')->nullable();
            $table->string('shipping_province')->nullable();
            $table->string('shipping_post_code')->nullable();
            $table->string('shipping_country')->nullable();

            $table->string('status')->default('UNPAID');
            $table->boolean('is_paid')->default(false);
            $table->boolean('is_canceled')->default(false);
            $table->string('cancel_reason')->nullable();
            $table->string('channel_status')->nullable();
            $table->string('payment_method')->nullable();
            $table->string('source')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
