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
            $table->boolean('stock_push_enabled')->default(true)->after('shadow_last_pulled_at');
            $table->unsignedInteger('stock_push_buffer')->default(0)->after('stock_push_enabled');
            $table->timestamp('stock_handover_at')->nullable()->after('stock_push_buffer');
        });

        DB::table('channel_shops')
            ->where('is_shadow_mode', true)
            ->update(['stock_push_enabled' => false]);
    }

    public function down(): void
    {
        Schema::table('channel_shops', function (Blueprint $table) {
            $table->dropColumn(['stock_push_enabled', 'stock_push_buffer', 'stock_handover_at']);
        });
    }
};
