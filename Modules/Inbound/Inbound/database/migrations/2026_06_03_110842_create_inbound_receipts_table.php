<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbound_receipts', function (Blueprint $table) {
            $table->id();
            $table->uuid('inbound_item_id');
            $table->integer('qty');
            $table->uuid('bin_id');
            $table->string('batch_no', 100)->nullable();
            $table->string('serial_no', 100)->nullable();
            $table->string('received_by', 100);
            $table->dateTime('received_date');
            $table->timestamps();

            $table->foreign('inbound_item_id')->references('id')->on('inbound_items')->onDelete('cascade');
            $table->foreign('bin_id')->references('id')->on('location_bins')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbound_receipts');
    }
};
