<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('channel_shops', function (Blueprint $table) {
            $table->timestamp('shadow_started_at')->nullable()->after('is_shadow_mode');
            $table->timestamp('shadow_last_pulled_at')->nullable()->after('shadow_started_at');
        });

        DB::table('channel_shops')
            ->where('is_shadow_mode', true)
            ->whereNull('shadow_started_at')
            ->update(['shadow_started_at' => now()]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('channel_shops', function (Blueprint $table) {
            $table->dropColumn(['shadow_started_at', 'shadow_last_pulled_at']);
        });
    }
};
