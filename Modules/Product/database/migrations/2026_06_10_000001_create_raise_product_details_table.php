<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('raise_product_details', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('raise_product_id');
            $table->uuid('product_channel_mapping_id');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_repeatable')->default(false);
            $table->boolean('is_success')->nullable();
            $table->timestamp('start_time')->nullable();
            $table->timestamp('end_time')->nullable();
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->foreign('raise_product_id')->references('id')->on('raise_products')->onDelete('cascade');
            $table->foreign('product_channel_mapping_id')->references('id')->on('product_channel_mappings')->onDelete('cascade');
            $table->unique('product_channel_mapping_id', 'raise_product_details_mapping_unique');
            $table->index('raise_product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('raise_product_details');
    }
};
