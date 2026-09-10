<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_returns', function (Blueprint $table): void {
            $table->string('tracking_sync_status', 30)->default('pending')->after('tracking_synced_at');
            $table->timestamp('tracking_sync_attempted_at')->nullable()->after('tracking_sync_status');
            $table->text('tracking_sync_last_error')->nullable()->after('tracking_sync_attempted_at');
            $table->index(
                ['tracking_sync_status', 'tracking_sync_attempted_at'],
                'sales_returns_tracking_sync_state_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('sales_returns', function (Blueprint $table): void {
            $table->dropIndex('sales_returns_tracking_sync_state_index');
            $table->dropColumn([
                'tracking_sync_status',
                'tracking_sync_attempted_at',
                'tracking_sync_last_error',
            ]);
        });
    }
};
