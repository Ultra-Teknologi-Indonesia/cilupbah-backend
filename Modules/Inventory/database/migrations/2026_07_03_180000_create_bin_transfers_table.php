<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bin_transfer_sequences', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('bin_transfers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('transfer_number', 30)->unique();
            $table->foreignUuid('location_id')->constrained('locations')->restrictOnDelete();
            $table->foreignUuid('source_bin_id')->constrained('location_bins')->restrictOnDelete();
            $table->foreignUuid('destination_bin_id')->constrained('location_bins')->restrictOnDelete();
            $table->date('transfer_date');
            $table->string('created_by', 100);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('transfer_date');
            $table->index('location_id');
        });

        Schema::create('bin_transfer_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('bin_transfer_id')->constrained('bin_transfers')->cascadeOnDelete();
            $table->foreignUuid('item_id')->constrained('product_variants')->restrictOnDelete();
            $table->integer('qty');
            $table->string('batch_no', 100)->nullable();
            $table->string('serial_no', 100)->nullable();
            $table->date('expired_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('bin_transfer_id');
            $table->index('item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bin_transfer_items');
        Schema::dropIfExists('bin_transfers');
        Schema::dropIfExists('bin_transfer_sequences');
    }
};
