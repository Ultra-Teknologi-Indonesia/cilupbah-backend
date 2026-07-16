<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assignment_history', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('subject_type');
            $table->uuid('subject_id');
            $table->uuid('from_user_id')->nullable();
            $table->uuid('to_user_id')->nullable();
            $table->uuid('actor_id')->nullable();
            $table->string('action', 32);
            $table->string('channel', 16)->nullable();
            $table->string('reason_code', 32)->nullable();
            $table->text('reason_note')->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id'], 'assignment_history_subject_idx');
            $table->index('actor_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assignment_history');
    }
};
