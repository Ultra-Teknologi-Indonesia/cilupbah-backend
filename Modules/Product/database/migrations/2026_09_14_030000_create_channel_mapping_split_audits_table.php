<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_mapping_split_audits', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('source_mapping_id');
            $table->uuid('target_mapping_id');
            $table->uuid('channel_shop_id');
            $table->string('external_product_id')->nullable();
            $table->uuid('source_product_id');
            $table->uuid('target_product_id');
            $table->unsignedInteger('moved_models');
            $table->boolean('created_target_mapping');
            $table->timestampTz('repaired_at');
            $table->timestamps();

            $table->index('source_mapping_id');
            $table->index('target_mapping_id');
            $table->index('channel_shop_id');
            $table->index('repaired_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_mapping_split_audits');
    }
};
