<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_order_import_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('batch_no')->unique();
            $table->uuid('executed_by')->nullable();
            $table->string('original_filename');
            $table->string('stored_path');
            $table->enum('state', ['queued', 'processing', 'done', 'done_with_errors', 'failed'])->default('queued');
            $table->integer('total_rows')->default(0);
            $table->integer('processed_rows')->default(0);
            $table->integer('success_rows')->default(0);
            $table->integer('failed_rows')->default(0);
            $table->unsignedTinyInteger('progress_percent')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->foreign('executed_by')->references('id')->on('users')->nullOnDelete();
            $table->index('state');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_order_import_batches');
    }
};
