<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->string('id', 32)->primary();
            $table->string('adjustment_no', 50)->unique();
            $table->dateTime('transaction_date');
            $table->string('location_id', 32);
            $table->foreign('location_id')->references('id')->on('locations')->restrictOnDelete();
            $table->enum('status', ['DRAFT', 'APPROVED', 'CANCELLED'])->default('DRAFT');
            $table->boolean('is_beginning_balance')->default(false);
            $table->text('notes')->nullable();
            $table->string('created_by', 100);
            $table->string('approved_by', 100)->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('location_id');
            $table->index('status');
            $table->index('transaction_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_adjustments');
    }
};
