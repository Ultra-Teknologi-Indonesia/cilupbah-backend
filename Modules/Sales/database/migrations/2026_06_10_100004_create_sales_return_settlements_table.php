<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_return_settlements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('settlement_number', 50)->unique();
            $table->foreignUuid('return_id')->nullable()->constrained('sales_returns')->nullOnDelete();
            $table->string('status', 30)->default('DRAFT');
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->text('notes')->nullable();
            $table->string('created_by', 100);
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_return_settlements');
    }
};
