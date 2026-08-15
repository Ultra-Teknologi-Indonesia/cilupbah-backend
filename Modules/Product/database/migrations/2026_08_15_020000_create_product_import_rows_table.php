<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_import_rows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('import_batch_id');
            $table->integer('row_number');
            $table->string('sku')->nullable();
            $table->string('name')->nullable();
            $table->string('category_name')->nullable();
            $table->decimal('sell_price', 15, 2)->nullable();
            $table->string('status', 30)->default('valid');
            $table->text('message')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('import_batch_id')
                ->references('id')
                ->on('product_import_batches')
                ->cascadeOnDelete();

            $table->index(['import_batch_id', 'row_number']);
            $table->index(['import_batch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_import_rows');
    }
};
