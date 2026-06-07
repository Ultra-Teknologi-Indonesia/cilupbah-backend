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
        Schema::create('attribute_channel_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attribute_id')->constrained('attributes')->cascadeOnDelete();
            $table->string('channel_attribute_id', 32);
            $table->foreign('channel_attribute_id')->references('id')->on('channel_attributes')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['attribute_id', 'channel_attribute_id'], 'attr_chan_mapping_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attribute_channel_mappings');
    }
};
