<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warranties', function (Blueprint $table) {
            $table->string('id', 32)->primary();
            $table->string('product_variant_id', 32);
            $table->string('order_id', 32)->nullable();
            $table->string('serial_no', 100)->nullable();
            $table->integer('duration_months');
            $table->date('start_date');
            $table->date('end_date');
            $table->enum('status', ['ACTIVE', 'EXPIRED', 'VOIDED'])->default('ACTIVE');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('product_variant_id')->references('id')->on('product_variants')->restrictOnDelete();
            $table->foreign('order_id')->references('id')->on('orders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warranties');
    }
};
