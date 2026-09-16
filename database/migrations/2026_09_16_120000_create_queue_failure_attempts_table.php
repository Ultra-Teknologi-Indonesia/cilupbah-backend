<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('queue_failure_attempts', function (Blueprint $table): void {
            $table->id();
            $table->string('job_uuid', 191)->nullable();
            $table->string('connection', 191)->nullable();
            $table->string('queue', 191)->nullable();
            $table->text('job_class')->nullable();
            $table->text('exception_class');
            $table->text('exception_message');
            $table->text('exception_code')->nullable();
            $table->text('exception_file')->nullable();
            $table->unsignedInteger('exception_line')->nullable();
            $table->longText('exception_trace')->nullable();
            $table->jsonb('exception_chain')->nullable();
            $table->jsonb('job_payload')->nullable();
            $table->unsignedInteger('attempt')->default(1);
            $table->string('event_type', 32);
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['job_uuid', 'attempt']);
            $table->index(['queue', 'occurred_at']);
            $table->unique(['job_uuid', 'attempt', 'event_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('queue_failure_attempts');
    }
};
