<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bin_multi_sku_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('location_id')->index();
            $table->string('pattern', 100);
            $table->string('note')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['location_id', 'pattern']);
            $table->foreign('location_id')->references('id')->on('locations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bin_multi_sku_rules');
    }
};
