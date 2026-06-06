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
        Schema::create('inbound_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('inbound_id');
            $table->unsignedBigInteger('item_id');
            $table->integer('expected_qty');
            $table->integer('received_qty')->default(0);
            $table->enum('condition', ['GOOD', 'DAMAGE'])->default('GOOD');
            $table->timestamps();

            $table->foreign('inbound_id')->references('id')->on('inbounds')->onDelete('cascade');
            $table->index('item_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inbound_items');
    }
};
