<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channel_sync_settings', function (Blueprint $table): void {
            $table->timestampTz('paused_at')->nullable();
            $table->timestampTz('resumed_at')->nullable();
            $table->date('auto_paused_on')->nullable();
            $table->string('pause_reason', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('channel_sync_settings', function (Blueprint $table): void {
            $table->dropColumn(['paused_at', 'resumed_at', 'auto_paused_on', 'pause_reason']);
        });
    }
};
