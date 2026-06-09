<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('picklists', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('picklist_no', 50)->unique();
            $table->uuid('location_id');
            $table->foreign('location_id')->references('id')->on('locations')->restrictOnDelete();
            $table->uuid('picker_id')->nullable();
            $table->foreign('picker_id')->references('id')->on('users')->nullOnDelete();
            $table->string('assigned_by', 100)->nullable();
            $table->enum('status', ['DRAFT', 'IN_PROGRESS', 'COMPLETED', 'CANCELLED'])->default('DRAFT');
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->text('notes')->nullable();
            $table->string('created_by', 100);
            $table->timestamps();

            $table->index('status');
            $table->index(['picker_id', 'status']);
            $table->index('location_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('picklists');
    }
};
