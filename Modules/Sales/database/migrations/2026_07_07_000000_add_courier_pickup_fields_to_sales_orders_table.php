<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {

            $table->string('courier_name')->nullable()->after('dropshipper_phone');
            $table->string('courier_phone', 32)->nullable()->after('courier_name');
            $table->string('pickup_code', 64)->nullable()->after('courier_phone');
            $table->timestamp('courier_pickup_recorded_at')->nullable()->after('pickup_code');
            $table->uuid('courier_pickup_recorded_by')->nullable()->after('courier_pickup_recorded_at');
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropColumn([
                'courier_name',
                'courier_phone',
                'pickup_code',
                'courier_pickup_recorded_at',
                'courier_pickup_recorded_by',
            ]);
        });
    }
};
