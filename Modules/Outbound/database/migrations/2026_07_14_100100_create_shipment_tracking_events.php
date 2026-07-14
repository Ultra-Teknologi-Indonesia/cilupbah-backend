<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipment_tracking_events', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('shipment_id')
                ->constrained('shipments')
                ->cascadeOnDelete();
            $table->string('source', 30);
            $table->string('event_type', 60);
            $table->string('driver_name', 100)->nullable();
            $table->string('driver_phone', 30)->nullable();
            $table->string('driver_vehicle_plate', 20)->nullable();
            $table->jsonb('raw_payload')->nullable();
            $table->dateTime('occurred_at');
            $table->dateTime('received_at');
            $table->timestamps();

            $table->index(['shipment_id', 'occurred_at']);
            $table->index(['source', 'event_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_tracking_events');
    }
};
