<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('putaway_item_sources', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('putaway_item_id');
            $table->foreign('putaway_item_id')->references('id')->on('putaway_items')->cascadeOnDelete();
            $table->uuid('inbound_item_id');
            $table->foreign('inbound_item_id')->references('id')->on('inbound_items')->restrictOnDelete();
            // Porsi qty dari inbound_item ini yang masuk ke putaway_item induk.
            $table->integer('qty');
            // Porsi yang sudah ditempatkan (untuk distribusi sinkron balik ke inbound_item).
            $table->integer('putaway_qty')->default(0);
            $table->timestamps();

            $table->index('putaway_item_id');
            $table->index('inbound_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('putaway_item_sources');
    }
};
