<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_mapping_repair_audits', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('mapping_id');
            $table->uuid('channel_shop_id');
            $table->string('external_product_id')->nullable();
            $table->uuid('old_product_id');
            $table->uuid('new_product_id');
            $table->unsignedInteger('models');
            $table->timestampTz('repaired_at');
            $table->timestamps();

            $table->index('mapping_id');
            $table->index('channel_shop_id');
            $table->index('repaired_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_mapping_repair_audits');
    }
};
