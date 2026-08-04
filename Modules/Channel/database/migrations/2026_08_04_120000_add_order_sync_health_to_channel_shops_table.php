<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channel_shops', function (Blueprint $table) {
            $table->string('order_sync_status')->default('pending')->after('last_synced_at');
            $table->timestamp('last_order_synced_at')->nullable()->after('order_sync_status');
            $table->text('last_order_error')->nullable()->after('last_order_synced_at');
            $table->timestamp('last_order_error_at')->nullable()->after('last_order_error');
        });
    }

    public function down(): void
    {
        Schema::table('channel_shops', function (Blueprint $table) {
            $table->dropColumn([
                'order_sync_status',
                'last_order_synced_at',
                'last_order_error',
                'last_order_error_at',
            ]);
        });
    }
};
