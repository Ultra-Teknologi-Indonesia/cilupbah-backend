<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_return_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->boolean('auto_accept')->default(false);
            $table->uuid('default_restock_location_id')->nullable();
            $table->jsonb('allowed_conditions')->nullable();
            $table->jsonb('allowed_refund_methods')->nullable();
            $table->unsignedInteger('return_validity_days')->nullable();
            $table->timestamps();

            $table->foreign('default_restock_location_id')->references('id')->on('locations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_return_settings');
    }
};
