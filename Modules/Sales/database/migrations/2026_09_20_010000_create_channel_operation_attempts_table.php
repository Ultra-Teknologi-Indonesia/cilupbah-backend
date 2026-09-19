<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_operation_attempts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('order_id');
            $table->string('operation', 100);
            $table->string('status', 20);
            $table->unsignedInteger('attempt_count')->default(1);
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('succeeded_at')->nullable();
            $table->timestamp('uncertain_at')->nullable();
            $table->text('last_error')->nullable();
            $table->json('last_response')->nullable();
            $table->timestamps();

            $table->unique(['order_id', 'operation'], 'uq_channel_operation_attempts_order_operation');
            $table->index(['status', 'updated_at'], 'ix_channel_operation_attempts_status_updated');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_operation_attempts');
    }
};
