<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_mapping_prune_audits', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('mapping_id');
            $table->uuid('channel_shop_id');
            $table->string('external_product_id')->nullable();
            $table->string('reason', 64);
            $table->unsignedInteger('deleted_children');
            $table->boolean('deleted_parent');
            $table->timestamp('pruned_at');
            $table->timestamps();

            $table->index(['channel_shop_id', 'pruned_at']);
            $table->index(['mapping_id', 'pruned_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_mapping_prune_audits');
    }
};
