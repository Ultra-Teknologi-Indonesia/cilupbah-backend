<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_return_appeals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('sales_return_id')->constrained('sales_returns')->cascadeOnDelete();
            $table->string('record_type', 50);
            $table->string('operator', 30);
            $table->text('description')->nullable();
            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->index(['sales_return_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_return_appeals');
    }
};
