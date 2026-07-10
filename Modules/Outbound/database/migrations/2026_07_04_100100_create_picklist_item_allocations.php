<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('picklist_item_allocations', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('picklist_item_id');
            $table->foreign('picklist_item_id')
                ->references('id')->on('picklist_items')
                ->cascadeOnDelete();

            $table->uuid('bin_id');
            $table->foreign('bin_id')
                ->references('id')->on('location_bins')
                ->restrictOnDelete();

            $table->unsignedInteger('qty');

            $table->dateTime('picked_at');

            $table->uuid('picked_by')->nullable();
            $table->foreign('picked_by')
                ->references('id')->on('users')
                ->nullOnDelete();

            $table->uuid('movement_id')->nullable();

            $table->timestamps();

            $table->index('picklist_item_id');
            $table->index(['picklist_item_id', 'bin_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('picklist_item_allocations');
    }
};
