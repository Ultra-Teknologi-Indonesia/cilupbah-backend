<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_sync_states', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('order_id')->unique();
            $table->string('status', 24)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->string('request_key', 128)->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['status', 'next_attempt_at']);
            $table->index('last_attempt_at');
        });

        Schema::create('finance_sync_dead_letters', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('job_uuid', 191)->unique();
            $table->uuid('order_id')->nullable();
            $table->string('source', 32)->nullable();
            $table->string('channel_order_no')->nullable();
            $table->string('exception_class', 191);
            $table->unsignedInteger('attempts')->default(0);
            $table->text('reason');
            $table->jsonb('context')->nullable();
            $table->timestamp('failed_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['source', 'failed_at']);
            $table->index(['order_id', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_sync_dead_letters');
        Schema::dropIfExists('finance_sync_states');
    }
};
