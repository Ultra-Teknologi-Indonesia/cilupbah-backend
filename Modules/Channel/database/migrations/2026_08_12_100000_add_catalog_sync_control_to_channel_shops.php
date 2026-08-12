<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channel_shops', function (Blueprint $table) {
            $table->boolean('catalog_pull_enabled')->default(true)->after('stock_push_buffer');
            $table->boolean('catalog_push_enabled')->default(true)->after('catalog_pull_enabled');
        });

        DB::table('channel_shops')
            ->where('stock_push_enabled', false)
            ->update(['catalog_push_enabled' => false]);
    }

    public function down(): void
    {
        Schema::table('channel_shops', function (Blueprint $table) {
            $table->dropColumn(['catalog_pull_enabled', 'catalog_push_enabled']);
        });
    }
};
