<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_cutover_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->timestampTz('cutoff_at');
            $table->jsonb('location_codes');
            $table->jsonb('source_files')->nullable();
            $table->jsonb('report')->nullable();
            $table->string('status', 30)->default('PREFLIGHT');
            $table->string('created_by', 100);
            $table->timestampTz('applied_at')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'cutoff_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_cutover_runs');
    }
};
