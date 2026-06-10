<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_return_settlement_refunds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('settlement_id')->constrained('sales_return_settlements')->cascadeOnDelete();
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
        Schema::dropIfExists('sales_return_settlement_refunds');
    }
};
