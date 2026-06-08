<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('shipment_no', 50)->unique();
            $table->uuid('location_id');
            $table->foreign('location_id')->references('id')->on('locations')->restrictOnDelete();
            $table->string('courier_name', 100)->nullable();
            $table->string('courier_code', 50)->nullable();
            $table->enum('shipment_type', ['REGULAR', 'EXPRESS', 'SAME_DAY', 'CARGO'])->default('REGULAR');
            $table->date('shipment_date');
            $table->enum('status', ['SCHEDULED', 'HANDED_OVER', 'IN_TRANSIT', 'DELIVERED', 'CANCELLED'])->default('SCHEDULED');
            $table->dateTime('handed_over_at')->nullable();
            $table->text('notes')->nullable();
            $table->string('created_by', 100);
            $table->timestamps();

            $table->index('status');
            $table->index('location_id');
            $table->index('shipment_date');
            $table->index('courier_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipments');
    }
};
