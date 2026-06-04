<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->string('location_code', 50)->unique();
            $table->string('location_name', 255);
            $table->string('location_type', 50);
            
            $table->text('address')->nullable();
            $table->string('area', 100)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('province', 100)->nullable();
            $table->string('post_code', 20)->nullable();
            
            $table->boolean('is_warehouse')->default(true);
            $table->boolean('is_multi_origin')->default(false);
            $table->string('default_warehouse_user', 255)->nullable();
            $table->boolean('is_active')->default(true);
            
            $table->boolean('is_fbl')->nullable();
            $table->boolean('is_tcb')->nullable();
            $table->boolean('is_fbs')->nullable(); 
            
            $table->timestamps();
            
            $table->index(['is_active', 'location_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('locations');
    }
};
