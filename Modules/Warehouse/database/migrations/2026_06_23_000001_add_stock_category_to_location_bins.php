<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('location_bins', function (Blueprint $table) {
            $table->boolean('is_stock_acknowledged')->default(true)->after('is_inbound');
            $table->boolean('is_large_bin')->default(false)->after('is_stock_acknowledged');
            $table->string('category')->nullable()->after('is_large_bin');
        });
    }

    public function down(): void
    {
        Schema::table('location_bins', function (Blueprint $table) {
            $table->dropColumn(['is_stock_acknowledged', 'is_large_bin', 'category']);
        });
    }
};
