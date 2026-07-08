<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            // Pin lokasi penerima (format "(lat,lng)") — samakan dengan Manajemen Gudang.
            $table->string('shipping_coordinate', 64)->nullable()->after('shipping_country');
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropColumn('shipping_coordinate');
        });
    }
};
