<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channel_shops', function (Blueprint $table) {
            $table->string('integration_status')->default('normal')->after('disconnected_at');
            $table->text('last_error')->nullable()->after('integration_status');
            $table->timestamp('last_synced_at')->nullable()->after('last_error');
        });
    }

    public function down(): void
    {
        Schema::table('channel_shops', function (Blueprint $table) {
            $table->dropColumn(['integration_status', 'last_error', 'last_synced_at']);
        });
    }
};
