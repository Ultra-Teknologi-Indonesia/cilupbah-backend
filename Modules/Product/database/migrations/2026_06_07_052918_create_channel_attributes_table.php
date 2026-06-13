<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::create('channel_attributes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('channel_category_id');
            $table->foreign('channel_category_id')->references('id')->on('channel_categories')->cascadeOnDelete();
            $table->string('external_id')->index(); 
            $table->string('name');
            $table->boolean('is_required')->default(false);
            $table->boolean('is_multiple')->default(false);
            $table->timestamps();

            $table->unique(['channel_category_id', 'external_id'], 'chan_cat_ext_attr_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_attributes');
    }
};
