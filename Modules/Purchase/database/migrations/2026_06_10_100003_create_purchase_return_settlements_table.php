<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_return_settlements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('settlement_number', 50)->unique();
            $table->foreignUuid('return_id')->nullable()->constrained('purchase_returns')->nullOnDelete();
            $table->string('status', 30)->default('DRAFT');
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->text('notes')->nullable();
            $table->string('created_by', 100);
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('purchase_return_settlement_bills', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('settlement_id')->constrained('purchase_return_settlements')->cascadeOnDelete();
            $table->foreignUuid('bill_id')->constrained('purchase_bills')->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->timestamps();
        });

        Schema::create('purchase_return_settlement_refunds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('settlement_id')->constrained('purchase_return_settlements')->cascadeOnDelete();
            $table->string('refund_number', 50);
            $table->decimal('amount', 15, 2);
            $table->string('refund_method', 100);
            $table->date('refund_date');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_return_settlement_refunds');
        Schema::dropIfExists('purchase_return_settlement_bills');
        Schema::dropIfExists('purchase_return_settlements');
    }
};
