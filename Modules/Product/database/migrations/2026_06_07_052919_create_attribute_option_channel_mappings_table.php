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
        Schema::create('attribute_option_channel_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attribute_option_id')->constrained('attribute_options')->cascadeOnDelete();
            $table->uuid('channel_attribute_option_id')->constrained('channel_attribute_options')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['attribute_option_id', 'channel_attribute_option_id'], 'attr_opt_chan_mapping_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attribute_option_channel_mappings');
    }
};
