<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('agent_device', 50)->default('Other');
            $table->string('agent_os', 50)->default('Other');
            $table->string('agent_browser', 50)->default('Other');
            $table->string('ip_address', 100)->nullable();
            $table->string('location_country', 100)->default('-');
            $table->string('location_region', 100)->default('-');
            $table->string('location_city', 100)->default('-');
            $table->string('location_district', 100)->default('-');
            $table->string('location_village', 100)->default('-');
            $table->decimal('location_lat', 10, 7)->nullable();
            $table->decimal('location_lon', 10, 7)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_histories');
    }
};
