<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbound_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('inbound_id');
            $table->foreign('inbound_id')->references('id')->on('inbounds')->onDelete('cascade');
            $table->string('assigned_to', 32);
            $table->foreign('assigned_to')->references('id')->on('users')->onDelete('cascade');
            $table->string('assigned_by', 32);
            $table->foreign('assigned_by')->references('id')->on('users')->onDelete('cascade');
            $table->string('status', 30)->default('PENDING');
            $table->text('notes')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['inbound_id', 'assigned_to']);
            $table->index(['assigned_to', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbound_assignments');
    }
};
