<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->uuid('default_bin_id')->nullable()->after('village_id');
            $table->foreign('default_bin_id')->references('id')->on('location_bins')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->dropForeign(['default_bin_id']);
            $table->dropColumn('default_bin_id');
        });
    }
};
