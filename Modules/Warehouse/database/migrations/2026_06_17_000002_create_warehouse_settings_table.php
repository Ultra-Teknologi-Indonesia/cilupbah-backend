<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouse_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Toggle global "Gunakan Layout Gudang".
            $table->boolean('use_warehouse_layout')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_settings');
    }
};
