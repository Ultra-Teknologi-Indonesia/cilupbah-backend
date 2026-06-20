<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_import_errors', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('import_batch_id');
            $table->integer('row_number');
            $table->string('attribute')->nullable();
            $table->string('message', 1000);
            $table->json('row_snapshot')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->foreign('import_batch_id')->references('id')->on('product_import_batches')->cascadeOnDelete();
            $table->index('import_batch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_import_errors');
    }
};
