<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_warehouses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained('locations')->cascadeOnDelete();

            $table->integer('channel_id');
            $table->string('store_id', 255);
            $table->string('channel_location_id', 255);
            $table->string('channel_location_type', 50)->nullable(); 

            $table->timestamps();

            $table->index(['channel_id', 'store_id', 'channel_location_id'], 'idx_channel_mapping');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_warehouses');
    }
};
