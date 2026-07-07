<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('impex_activities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->enum('direction', ['import', 'export']);
            $table->string('activity_type');
            $table->uuid('user_id')->nullable();
            $table->string('location_name')->nullable();
            $table->enum('status', ['pending', 'processing', 'success', 'failed'])->default('pending');
            $table->unsignedTinyInteger('progress_percentage')->nullable();
            $table->text('file_url')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->index('direction');
            $table->index('status');
            $table->index('user_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('impex_activities');
    }
};
