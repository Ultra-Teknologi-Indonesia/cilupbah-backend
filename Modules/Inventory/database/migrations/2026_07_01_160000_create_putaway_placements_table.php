<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('putaway_placements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('putaway_item_id');
            $table->uuid('bin_id');
            $table->unsignedInteger('qty')->default(0);
            $table->timestamps();

            $table->foreign('putaway_item_id')
                ->references('id')
                ->on('putaway_items')
                ->cascadeOnDelete();

            $table->foreign('bin_id')
                ->references('id')
                ->on('location_bins')
                ->cascadeOnDelete();

            $table->unique(['putaway_item_id', 'bin_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('putaway_placements');
    }
};
