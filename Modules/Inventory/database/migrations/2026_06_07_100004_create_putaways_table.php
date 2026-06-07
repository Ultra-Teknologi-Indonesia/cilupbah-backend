<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('putaways', function (Blueprint $table) {
            $table->string('id', 32)->primary();
            $table->string('putaway_no', 50)->unique();
            $table->string('location_id', 32);
            $table->foreign('location_id')->references('id')->on('locations')->restrictOnDelete();
            $table->string('source_type', 30);
            $table->string('source_id', 32)->nullable();
            $table->enum('status', ['NOT_STARTED', 'IN_PROGRESS', 'COMPLETED', 'CANCELLED'])->default('NOT_STARTED');
            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->foreign('assigned_to')->references('id')->on('users')->nullOnDelete();
            $table->string('assigned_by', 100)->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->text('notes')->nullable();
            $table->string('created_by', 100);
            $table->timestamps();

            $table->index('status');
            $table->index(['assigned_to', 'status']);
            $table->index('location_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('putaways');
    }
};
