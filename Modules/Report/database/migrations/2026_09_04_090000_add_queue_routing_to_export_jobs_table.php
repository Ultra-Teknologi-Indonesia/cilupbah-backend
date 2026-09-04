<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('export_jobs', function (Blueprint $table): void {
            $table->string('queue_connection', 64)->nullable()->after('status');
            $table->string('queue_name', 128)->nullable()->after('queue_connection');
            $table->index(['queue_name', 'status', 'created_at'], 'export_jobs_queue_status_created_idx');
        });
    }

    public function down(): void
    {
        Schema::table('export_jobs', function (Blueprint $table): void {
            $table->dropIndex('export_jobs_queue_status_created_idx');
            $table->dropColumn(['queue_connection', 'queue_name']);
        });
    }
};
