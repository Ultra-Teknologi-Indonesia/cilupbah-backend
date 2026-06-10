<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_serial_numbers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('purchase_bill_item_id')->constrained('purchase_bill_items')->cascadeOnDelete();
            $table->string('serial_number')->nullable();
            $table->string('batch_number')->nullable();
            $table->date('expired_date')->nullable();
            $table->timestamp('printed_at')->nullable();
            $table->string('printed_by', 100)->nullable();
            $table->timestamps();

            $table->index('purchase_bill_item_id');
            $table->index('serial_number');
            $table->index('batch_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_serial_numbers');
    }
};
