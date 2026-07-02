<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipment_orders', function (Blueprint $table) {
            $table->string('pickup_status', 20)->nullable()->after('qty_given');
            $table->text('pickup_message')->nullable()->after('pickup_status');
        });
    }

    public function down(): void
    {
        Schema::table('shipment_orders', function (Blueprint $table) {
            $table->dropColumn(['pickup_status', 'pickup_message']);
        });
    }
};
