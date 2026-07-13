<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bulk_shipping_label_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->enum('status', ['processing', 'ready', 'failed'])->default('processing');
            $table->json('per_channel_opts')->nullable();
            $table->integer('total_count')->default(0);
            $table->integer('done_count')->default(0);
            $table->integer('failed_count')->default(0);
            $table->string('merged_pdf_path')->nullable();
            $table->unsignedBigInteger('merged_pdf_bytes')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bulk_shipping_label_batches');
    }
};
