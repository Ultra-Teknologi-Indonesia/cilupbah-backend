<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_locations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('location_id')->constrained('locations')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'location_id']);
        });

        Schema::create('user_location_zones', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_location_id')->constrained('user_locations')->cascadeOnDelete();
            $table->foreignUuid('zone_id')->constrained('location_zones')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_location_id', 'zone_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_location_zones');
        Schema::dropIfExists('user_locations');
    }
};
