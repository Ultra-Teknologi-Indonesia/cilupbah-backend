<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->boolean('is_small_warehouse')->default(false)->after('is_warehouse');
        });

        DB::table('locations')
            ->where('location_code', 'WH-KECIL')
            ->orWhere('location_code', 'O')
            ->orWhere('location_code', 'GK')
            ->orWhere('location_name', 'like', '%kecil%')
            ->orWhere('id', '019f9932-eda5-7055-b4e8-e9909d1df3d4')
            ->update(['is_small_warehouse' => true]);
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->dropColumn('is_small_warehouse');
        });
    }
};
