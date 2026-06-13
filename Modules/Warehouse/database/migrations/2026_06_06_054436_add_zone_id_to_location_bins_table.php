<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::table('location_bins', function (Blueprint $table) {
            $table->foreignId('zone_id')->nullable()->constrained('location_zones')->cascadeOnDelete()->after('location_id');
        });
    }

    public function down(): void
    {
        Schema::table('location_bins', function (Blueprint $table) {
            $table->dropForeign(['zone_id']);
            $table->dropColumn('zone_id');
        });
    }
};
