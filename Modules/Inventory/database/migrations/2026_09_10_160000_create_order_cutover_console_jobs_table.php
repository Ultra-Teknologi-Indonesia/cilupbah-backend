<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_cutover_console_jobs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type', 16);
            $table->string('status', 16)->default('queued');
            $table->jsonb('files');
            $table->jsonb('location_codes');
            $table->timestampTz('cutoff_at');
            $table->jsonb('report')->nullable();
            $table->string('report_disk', 32)->nullable();
            $table->string('report_path')->nullable();
            $table->text('error')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_cutover_console_jobs');
    }
};
