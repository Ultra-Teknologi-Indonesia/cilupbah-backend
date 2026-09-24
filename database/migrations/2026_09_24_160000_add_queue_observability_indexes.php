<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('channel_webhook_inbox')) {
            Schema::table('channel_webhook_inbox', function (Blueprint $table): void {
                $table->index(
                    ['status', 'received_at'],
                    'channel_webhook_inbox_status_received_at_index',
                );
            });
        }

        if (Schema::hasTable('failed_jobs')) {
            Schema::table('failed_jobs', function (Blueprint $table): void {
                $table->index('failed_at', 'failed_jobs_failed_at_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('failed_jobs')) {
            Schema::table('failed_jobs', function (Blueprint $table): void {
                $table->dropIndex('failed_jobs_failed_at_index');
            });
        }

        if (Schema::hasTable('channel_webhook_inbox')) {
            Schema::table('channel_webhook_inbox', function (Blueprint $table): void {
                $table->dropIndex('channel_webhook_inbox_status_received_at_index');
            });
        }
    }
};
