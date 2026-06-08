<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_opnames', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('opname_no', 50)->unique();
            $table->uuid('location_id');
            $table->foreign('location_id')->references('id')->on('locations')->restrictOnDelete();
            $table->uuid('filter_zone_id')->nullable();
            $table->foreign('filter_zone_id')->references('id')->on('location_zones')->nullOnDelete();
            $table->json('filter_floor_codes')->nullable();
            $table->json('filter_row_codes')->nullable();
            $table->json('filter_column_codes')->nullable();
            $table->enum('status', ['DRAFT', 'IN_PROGRESS', 'FINALIZED', 'CANCELLED'])->default('DRAFT');
            $table->text('notes')->nullable();
            $table->string('created_by', 100);
            $table->string('process_by', 100)->nullable();
            $table->string('finalized_by', 100)->nullable();
            $table->dateTime('finalized_at')->nullable();
            $table->string('printed_by', 100)->nullable();
            $table->dateTime('printed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('location_id');
            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_opnames');
    }
};
