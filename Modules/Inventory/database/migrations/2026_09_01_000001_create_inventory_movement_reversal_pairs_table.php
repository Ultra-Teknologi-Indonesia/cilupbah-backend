<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_movement_reversal_pairs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('original_movement_id');
            $table->uuid('reversal_movement_id');
            $table->timestamp('created_at')->useCurrent();

            $table->unique('original_movement_id', 'uq_reversal_pairs_original');
            $table->unique('reversal_movement_id', 'uq_reversal_pairs_reversal');
            $table->foreign('original_movement_id', 'fk_reversal_pairs_original')
                ->references('id')->on('inventory_movements')->cascadeOnDelete();
            $table->foreign('reversal_movement_id', 'fk_reversal_pairs_reversal')
                ->references('id')->on('inventory_movements')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movement_reversal_pairs');
    }
};
