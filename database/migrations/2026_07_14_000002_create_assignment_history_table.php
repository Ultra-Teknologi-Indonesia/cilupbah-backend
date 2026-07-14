<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assignment_history', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->string('subject_type');
            $table->char('subject_id', 26);
            $table->char('from_user_id', 26)->nullable();
            $table->char('to_user_id', 26)->nullable();
            $table->char('actor_id', 26)->nullable();
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
