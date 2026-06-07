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
        Schema::create('channel_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('channel_id')->constrained('channels')->cascadeOnDelete();
            $table->string('external_id')->index(); // TikTok ID like "601739"
            $table->string('parent_external_id')->default('0')->index();
            $table->string('name');
            $table->boolean('is_leaf')->default(false);
            $table->timestamps();

            // Make sure external_id is unique per channel
            $table->unique(['channel_id', 'external_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('channel_categories');
    }
};
