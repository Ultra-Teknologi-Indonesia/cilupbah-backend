<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('type', 16);              // percent | fixed
            $table->decimal('value', 15, 2)->default(0);
            $table->timestamp('start_at')->nullable();
            $table->timestamp('end_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
        });

        Schema::create('promotion_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('promotion_id');
            $table->uuid('product_id');
            $table->timestamps();

            $table->index('promotion_id');
            $table->index('product_id');
            $table->unique(['promotion_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_items');
        Schema::dropIfExists('promotions');
    }
};
