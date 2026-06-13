<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::create('category_channel_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->uuid('channel_category_id');
            $table->foreign('channel_category_id')->references('id')->on('channel_categories')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['category_id', 'channel_category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_channel_mappings');
    }
};
