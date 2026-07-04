<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_order_number_counters', function (Blueprint $table) {
            $table->id();
            $table->string('prefix', 32);
            $table->string('period_key', 8);
            $table->unsignedBigInteger('last_seq')->default(0);
            $table->timestamps();

            $table->unique(['prefix', 'period_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_order_number_counters');
    }
};
