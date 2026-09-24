<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('export_jobs', function (Blueprint $table): void {
            $table->index(['user_id', 'created_at', 'id'], 'export_jobs_user_created_id_idx');
            $table->index(['user_id', 'status', 'created_at', 'id'], 'export_jobs_user_status_created_id_idx');
        });
    }

    public function down(): void
    {
        Schema::table('export_jobs', function (Blueprint $table): void {
            $table->dropIndex('export_jobs_user_created_id_idx');
            $table->dropIndex('export_jobs_user_status_created_id_idx');
        });
    }
};
