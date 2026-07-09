<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->string('driver_name', 100)->nullable()->after('notes');
            $table->string('driver_phone', 30)->nullable()->after('driver_name');
            $table->string('driver_vehicle_plate', 20)->nullable()->after('driver_phone');
            $table->string('driver_booking_code', 100)->nullable()->after('driver_vehicle_plate');
            $table->string('driver_call_method', 20)->default('MANUAL')->after('driver_booking_code');
            $table->string('driver_call_status', 20)->default('NONE')->after('driver_call_method');
            $table->dateTime('driver_called_at')->nullable()->after('driver_call_status');
            $table->string('driver_called_by', 100)->nullable()->after('driver_called_at');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropColumn([
                'driver_name',
                'driver_phone',
                'driver_vehicle_plate',
                'driver_booking_code',
                'driver_call_method',
                'driver_call_status',
                'driver_called_at',
                'driver_called_by',
            ]);
        });
    }
};
