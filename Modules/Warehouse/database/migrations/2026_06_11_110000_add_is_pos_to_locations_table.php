<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            // Penanda lokasi berfungsi sebagai outlet POS (Point of Sale).
            // Nullable agar lokasi lama tidak otomatis dianggap POS.
            $table->boolean('is_pos')->nullable()->after('is_fbs');
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->dropColumn('is_pos');
        });
    }
};
