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
        Schema::create('channel_attribute_options', function (Blueprint $table) {
            $table->string('id', 32)->primary();
            $table->string('channel_attribute_id', 32);
            $table->foreign('channel_attribute_id')->references('id')->on('channel_attributes')->cascadeOnDelete();
            $table->string('external_id')->index(); // TikTok Value ID e.g., "1001182"
            $table->string('name');
            $table->timestamps();

            $table->unique(['channel_attribute_id', 'external_id'], 'chan_attr_opt_ext_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('channel_attribute_options');
    }
};
