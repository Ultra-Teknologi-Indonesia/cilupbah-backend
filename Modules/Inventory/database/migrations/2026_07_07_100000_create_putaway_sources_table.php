<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('putaway_sources', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('putaway_id');
            $table->foreign('putaway_id')->references('id')->on('putaways')->cascadeOnDelete();
            $table->uuid('inbound_id');
            $table->foreign('inbound_id')->references('id')->on('inbounds')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['putaway_id', 'inbound_id']);
            $table->index('inbound_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('putaway_sources');
    }
};
