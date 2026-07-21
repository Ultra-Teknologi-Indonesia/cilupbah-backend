<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_order_activities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('purchase_order_id');
            $table->string('entity_type', 16)->default('ORDER');
            $table->uuid('entity_id')->nullable();
            $table->string('action_id', 16);
            $table->string('action', 32);

            $table->uuid('actor_id')->nullable();
            $table->string('actor_name')->nullable();
            $table->string('actor_email')->nullable();

            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('purchase_order_id')->references('id')->on('purchase_orders')->cascadeOnDelete();
            $table->foreign('actor_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['purchase_order_id', 'created_at']);
            $table->index(['purchase_order_id', 'action_id', 'created_at'], 'po_act_action_cursor_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_activities');
    }
};
