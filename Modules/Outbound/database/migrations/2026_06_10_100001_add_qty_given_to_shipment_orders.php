<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipment_orders', function (Blueprint $table) {
            $table->integer('qty_given')->default(0)->after('tracking_number');
        });
    }

    public function down(): void
    {
        Schema::table('shipment_orders', function (Blueprint $table) {
            $table->dropColumn('qty_given');
        });
    }
};
