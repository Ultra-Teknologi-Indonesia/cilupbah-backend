<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('inventory_transfers');

        Schema::create('inventory_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('transfer_number', 50)->unique();
            $table->foreignId('source_location_id')->constrained('locations')->restrictOnDelete();
            $table->foreignId('destination_location_id')->constrained('locations')->restrictOnDelete();
            $table->string('status', 30)->default('DRAFT');
            $table->text('notes')->nullable();
            $table->string('created_by', 100);
            $table->string('received_by', 100)->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('inventory_transfer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_transfer_id')->constrained('inventory_transfers')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('products')->restrictOnDelete();
            $table->integer('qty');
            $table->integer('received_qty')->default(0);
            $table->uuid('source_bin_id')->nullable();
            $table->uuid('destination_bin_id')->nullable();
            $table->string('batch_no', 100)->default('');
            $table->string('serial_no', 100)->default('');
            $table->timestamps();

            $table->foreign('source_bin_id')->references('id')->on('location_bins')->nullOnDelete();
            $table->foreign('destination_bin_id')->references('id')->on('location_bins')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_transfer_items');
        Schema::dropIfExists('inventory_transfers');

        Schema::create('inventory_transfers', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
        });
    }
};
