<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('location_bins', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('location_id')->constrained('locations')->cascadeOnDelete();

            $table->string('floor_code', 20)->nullable();
            $table->string('row_code', 20)->nullable();
            $table->string('column_code', 20)->nullable();
            $table->string('bin_code', 20)->nullable();

            $table->string('bin_final_code', 100);
            $table->integer('max_qty')->default(0);
            $table->boolean('is_inbound')->default(false);

            $table->timestamps();

            $table->index('bin_final_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('location_bins');
    }
};
