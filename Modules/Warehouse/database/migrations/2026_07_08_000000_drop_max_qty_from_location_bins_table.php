<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('location_bins', function (Blueprint $table) {
            $table->dropColumn('max_qty');
        });
    }

    public function down(): void
    {
        Schema::table('location_bins', function (Blueprint $table) {
            $table->integer('max_qty')->default(0);
        });
    }
};
