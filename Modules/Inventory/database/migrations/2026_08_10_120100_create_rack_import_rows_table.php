<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rack_import_rows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('batch_id');
            $table->integer('row_no');

            $table->string('raw_sku')->nullable();
            $table->string('raw_location')->nullable();
            $table->string('raw_bin')->nullable();

            $table->uuid('item_id')->nullable();
            $table->uuid('location_id')->nullable();
            $table->uuid('bin_id')->nullable();

            $table->string('status');
            $table->string('message')->nullable();
            $table->string('product_name')->nullable();
            $table->string('current_bin')->nullable();

            $table->timestamp('created_at')->nullable();

            $table->foreign('batch_id')->references('id')->on('rack_import_batches')->cascadeOnDelete();
            $table->index(['batch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rack_import_rows');
    }
};
