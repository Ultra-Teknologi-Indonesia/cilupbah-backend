<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::create('channel_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('channel_id');
            $table->foreign('channel_id')->references('id')->on('channels')->cascadeOnDelete();
            $table->string('external_id')->index(); 
            $table->string('parent_external_id')->default('0')->index();
            $table->string('name');
            $table->boolean('is_leaf')->default(false);
            $table->timestamps();

            $table->unique(['channel_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_categories');
    }
};
