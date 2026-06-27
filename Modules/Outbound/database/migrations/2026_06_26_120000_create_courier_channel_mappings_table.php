<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courier_channel_mappings', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('channel_code', 50);

            $table->string('external_name');
            $table->string('external_id')->nullable();

            $table->uuid('courier_id');
            $table->foreign('courier_id')->references('id')->on('couriers')->cascadeOnDelete();

            $table->string('shipment_type', 50)->default('REGULAR');

            $table->boolean('is_verified')->default(false);

            $table->string('tenant_id', 100)->nullable();
            $table->timestamps();

            $table->unique(['channel_code', 'external_name', 'tenant_id'], 'ccm_channel_name_tenant_unique');
            $table->index(['courier_id', 'shipment_type']);
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courier_channel_mappings');
    }
};
