<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbound_backfill_reconciliations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('source_movement_id')->unique();
            $table->uuid('item_id')->index();
            $table->uuid('location_id')->index();
            $table->uuid('inbound_bin_id');
            $table->uuid('target_bin_id');
            $table->integer('qty');
            $table->string('strategy', 50);
            $table->uuid('run_id')->index();
            $table->string('applied_by', 100)->nullable();
            $table->timestamp('applied_at');
            $table->timestamps();

            $table->index(['location_id', 'target_bin_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbound_backfill_reconciliations');
    }
};
