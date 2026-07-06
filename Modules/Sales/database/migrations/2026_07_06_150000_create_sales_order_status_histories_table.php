<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_order_status_histories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('salesorder_id');
            $table->string('action_id', 8);
            $table->string('action', 32);
            $table->string('actor_email')->nullable();
            $table->uuid('actor_id')->nullable();
            $table->string('actor_name')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('salesorder_id')->references('id')->on('sales_orders')->cascadeOnDelete();
            $table->foreign('actor_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['salesorder_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_order_status_histories');
    }
};
