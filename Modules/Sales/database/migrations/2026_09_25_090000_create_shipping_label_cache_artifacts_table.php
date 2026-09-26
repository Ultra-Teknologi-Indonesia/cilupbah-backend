<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_label_cache_artifacts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('path')->unique();
            $table->string('spool_disk');
            $table->string('archive_disk');
            $table->char('sha256', 64);
            $table->unsignedBigInteger('bytes');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestampTz('next_attempt_at')->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->timestampTz('local_deleted_at')->nullable();
            $table->string('last_error', 1000)->nullable();
            $table->timestampsTz();
            $table->index(['archived_at', 'next_attempt_at'], 'label_cache_archive_due');
            $table->index(['local_deleted_at', 'archived_at'], 'label_cache_local_retention');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_label_cache_artifacts');
    }
};
