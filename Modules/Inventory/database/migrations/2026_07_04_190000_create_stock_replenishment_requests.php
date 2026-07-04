<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_replenishment_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('requested_by_user_id')->nullable();
            $table->uuid('from_location_id');
            $table->uuid('to_location_id');
            $table->enum('status', ['PENDING', 'ACCEPTED', 'REJECTED', 'DONE'])->default('PENDING');
            $table->uuid('assignee_user_id')->nullable();
            $table->uuid('accepted_by_user_id')->nullable();
            $table->uuid('rejected_by_user_id')->nullable();
            $table->timestamp('requested_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('done_at')->nullable();
            $table->string('reject_reason', 500)->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->foreign('from_location_id')->references('id')->on('locations');
            $table->foreign('to_location_id')->references('id')->on('locations');
            $table->index('status', 'idx_srr_status');
            $table->index('to_location_id', 'idx_srr_to_location');
        });

        Schema::create('stock_replenishment_request_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('request_id');
            $table->uuid('item_id');
            $table->string('sku', 64);
            $table->integer('qty');
            $table->string('reason', 500)->nullable();
            $table->timestamps();

            $table->foreign('request_id')
                ->references('id')->on('stock_replenishment_requests')
                ->cascadeOnDelete();
            $table->index('item_id', 'idx_srri_item');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_replenishment_request_items');
        Schema::dropIfExists('stock_replenishment_requests');
    }
};
