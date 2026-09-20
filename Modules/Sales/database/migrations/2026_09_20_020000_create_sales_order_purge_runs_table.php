<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_order_purge_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->timestamp('cutoff_at');
            $table->string('timezone', 64);
            $table->json('sources');
            $table->string('status', 20);
            $table->unsignedBigInteger('candidate_count')->default(0);
            $table->unsignedBigInteger('deleted_count')->default(0);
            $table->json('report')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'started_at']);
            $table->index('cutoff_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_order_purge_runs');
    }
};
