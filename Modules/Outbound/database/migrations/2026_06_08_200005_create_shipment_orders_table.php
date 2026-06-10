<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipment_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('shipment_id');
            $table->foreign('shipment_id')->references('id')->on('shipments')->cascadeOnDelete();
            $table->uuid('order_id');
            $table->foreign('order_id')->references('id')->on('orders')->restrictOnDelete();
            $table->uuid('packlist_id')->nullable();
            $table->foreign('packlist_id')->references('id')->on('packlists')->nullOnDelete();
            $table->string('tracking_number', 255)->nullable();
            $table->timestamps();

            $table->index('shipment_id');
            $table->unique(['shipment_id', 'order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_orders');
    }
};
