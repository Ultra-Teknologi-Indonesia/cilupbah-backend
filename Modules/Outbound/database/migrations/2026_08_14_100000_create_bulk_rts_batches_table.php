<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bulk_rts_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->nullable()->index();
            $table->string('status', 32)->default('processing')->index();
            $table->integer('total_count')->default(0);
            $table->integer('success_count')->default(0);
            $table->integer('failed_count')->default(0);
            $table->integer('skipped_count')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('bulk_rts_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('batch_id')->index();
            $table->uuid('order_id')->index();
            $table->string('salesorder_no', 100)->nullable();
            $table->string('source', 50)->nullable();
            $table->string('status', 32)->default('pending')->index();
            $table->text('message')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->foreign('batch_id')
                ->references('id')
                ->on('bulk_rts_batches')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bulk_rts_items');
        Schema::dropIfExists('bulk_rts_batches');
    }
};
