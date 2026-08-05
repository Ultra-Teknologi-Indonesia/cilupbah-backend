<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('download_transactions', function (Blueprint $table) {
            $table->unsignedInteger('total_failed')->default(0)->after('total_downloaded');
        });
    }

    public function down(): void
    {
        Schema::table('download_transactions', function (Blueprint $table) {
            $table->dropColumn('total_failed');
        });
    }
};
