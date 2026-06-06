<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();

            $table->uuid('item_id');
            $table->foreign('item_id')->references('id')->on('product_variants')->onDelete('cascade');
            $table->foreignId('location_id')->constrained('locations')->cascadeOnDelete();
            $table->uuid('bin_id')->nullable();

            $table->string('transaction_number', 100);
            $table->string('source', 50);

            $table->integer('qty');
            $table->integer('balance');

            $table->timestamp('transaction_date')->useCurrent();
            $table->string('created_by', 100);

            $table->timestamps();

            $table->foreign('bin_id')->references('id')->on('location_bins')->nullOnDelete();
            $table->index(['item_id', 'location_id', 'transaction_date']);
            $table->index('transaction_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
    }
};
