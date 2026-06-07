<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reserved_stocks', function (Blueprint $table) {
            $table->string('id', 32)->primary();
            $table->string('reserved_stock_no', 50)->unique();
            $table->string('location_id', 32);
            $table->foreign('location_id')->references('id')->on('locations')->restrictOnDelete();
            $table->dateTime('start_date');
            $table->dateTime('end_date');
            $table->enum('status', ['ACTIVE', 'EXPIRED', 'CANCELLED'])->default('ACTIVE');
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->string('created_by', 100);
            $table->timestamps();
            $table->softDeletes();

            $table->index('location_id');
            $table->index('status');
            $table->index('end_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reserved_stocks');
    }
};
