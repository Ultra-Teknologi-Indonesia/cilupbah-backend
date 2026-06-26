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

            // Source channel of the raw provider name (shopee|tiktok|lazada).
            $table->string('channel_code', 50);

            // Raw provider / logistics channel name and id as reported by the channel,
            // e.g. Shopee "SPX Express", TikTok "SPX Standard", Lazada "...".
            $table->string('external_name');
            $table->string('external_id')->nullable();

            // Canonical courier (master data) this raw provider resolves to.
            $table->uuid('courier_id');
            $table->foreign('courier_id')->references('id')->on('couriers')->cascadeOnDelete();

            // Service tier used to group orders into a manifest/shipment across channels.
            // Mirrors the values used by shipments.shipment_type (REGULAR, INSTANT,
            // EXPRESS, SAME_DAY, CARGO).
            $table->string('shipment_type', 50)->default('REGULAR');

            // Set to true once a human has reviewed/locked the auto-resolved mapping so
            // re-syncs don't overwrite manual corrections.
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
