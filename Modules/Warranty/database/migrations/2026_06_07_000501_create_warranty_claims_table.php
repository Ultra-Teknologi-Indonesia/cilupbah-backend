<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warranty_claims', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('warranty_id');
            $table->string('claim_number', 100)->unique();
            $table->text('reason');
            $table->enum('status', ['OPEN', 'IN_PROGRESS', 'APPROVED', 'REJECTED', 'RESOLVED'])->default('OPEN');
            $table->text('resolution')->nullable();
            $table->string('claimed_by', 100);
            $table->dateTime('claimed_at');
            $table->dateTime('resolved_at')->nullable();
            $table->timestamps();

            $table->foreign('warranty_id')->references('id')->on('warranties')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warranty_claims');
    }
};
