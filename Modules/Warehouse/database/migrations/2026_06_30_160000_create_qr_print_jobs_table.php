<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qr_print_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('location_id')->index();
            $table->uuid('user_id')->nullable()->index();
            $table->enum('status', ['pending', 'processing', 'ready', 'failed'])
                ->default('pending')
                ->index();
            $table->string('paper', 32)->default('thermal_50x40');
            $table->json('bin_ids')->nullable(); // null = semua bin di lokasi
            $table->unsignedInteger('total_bins')->default(0);
            $table->unsignedInteger('processed_bins')->default(0);
            $table->string('file_path', 500)->nullable();
            $table->string('error_message', 1000)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qr_print_jobs');
    }
};
