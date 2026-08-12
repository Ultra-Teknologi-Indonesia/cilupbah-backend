<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::table('channel_shops', function (Blueprint $table) {
            $table->boolean('is_shadow_mode')->default(false)->after('order_sync_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('channel_shops', function (Blueprint $table) {
            $table->dropColumn('is_shadow_mode');
        });
    }
};
