<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->enum('driver_call_status', ['pending', 'success', 'failed'])
                ->nullable()
                ->after('shipping_label_raw_data');
            $table->text('driver_call_message')->nullable()->after('driver_call_status');
            $table->timestamp('driver_call_attempted_at')->nullable()->after('driver_call_message');
            $table->json('driver_call_response')->nullable()->after('driver_call_attempted_at');
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropColumn([
                'driver_call_status',
                'driver_call_message',
                'driver_call_attempted_at',
                'driver_call_response',
            ]);
        });
    }
};
