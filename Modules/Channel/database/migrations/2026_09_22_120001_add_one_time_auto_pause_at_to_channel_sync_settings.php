<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channel_sync_settings', function (Blueprint $table): void {
            $table->timestampTz('auto_pause_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('channel_sync_settings', function (Blueprint $table): void {
            $table->dropColumn('auto_pause_at');
        });
    }
};
