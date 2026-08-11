<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sku_rack_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('location_id');
            $table->uuid('item_id');
            $table->uuid('bin_id');
            $table->uuid('assigned_by')->nullable();
            $table->timestamps();

            $table->unique(['location_id', 'item_id']);
            $table->index('bin_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sku_rack_assignments');
    }
};
