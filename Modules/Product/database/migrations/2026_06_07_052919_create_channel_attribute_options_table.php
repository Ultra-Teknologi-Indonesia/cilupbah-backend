<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::create('channel_attribute_options', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('channel_attribute_id');
            $table->foreign('channel_attribute_id')->references('id')->on('channel_attributes')->cascadeOnDelete();
            $table->string('external_id')->index(); 
            $table->string('name');
            $table->timestamps();

            $table->unique(['channel_attribute_id', 'external_id'], 'chan_attr_opt_ext_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_attribute_options');
    }
};
