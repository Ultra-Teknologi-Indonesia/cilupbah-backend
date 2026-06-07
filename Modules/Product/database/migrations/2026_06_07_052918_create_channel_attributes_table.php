<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('channel_attributes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('channel_category_id')->constrained('channel_categories')->cascadeOnDelete();
            $table->string('external_id')->index(); // TikTok Attribute ID e.g., "100393"
            $table->string('name');
            $table->boolean('is_required')->default(false);
            $table->boolean('is_multiple')->default(false);
            $table->timestamps();

            $table->unique(['channel_category_id', 'external_id'], 'chan_cat_ext_attr_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('channel_attributes');
    }
};
