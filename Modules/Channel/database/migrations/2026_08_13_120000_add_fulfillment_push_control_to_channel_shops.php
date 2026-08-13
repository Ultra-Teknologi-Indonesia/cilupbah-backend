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
            $table->boolean('fulfillment_push_enabled')->default(true)->after('stock_handover_at');
            $table->timestamp('fulfillment_handover_at')->nullable()->after('fulfillment_push_enabled');
        });

        DB::table('channel_shops')
            ->where(fn ($query) => $query->where('is_shadow_mode', true)->orWhere('stock_push_enabled', false))
            ->update(['fulfillment_push_enabled' => false]);
    }

    public function down(): void
    {
        Schema::table('channel_shops', function (Blueprint $table) {
            $table->dropColumn(['fulfillment_push_enabled', 'fulfillment_handover_at']);
        });
    }
};
