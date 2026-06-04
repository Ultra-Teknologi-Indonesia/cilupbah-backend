<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();
            $table->foreignId('bin_id')->nullable()->constrained('location_bins')->nullOnDelete();
            
            $table->string('batch_no', 100)->default('');
            $table->string('serial_no', 100)->default('');
            $table->timestamp('expired_date')->nullable();
            
            $table->integer('on_hand')->default(0);
            $table->integer('on_order')->default(0);
            $table->integer('reserved')->default(0);
            $table->integer('available')->default(0);
            
            $table->timestamps();

            $table->unique(
                ['item_id', 'location_id', 'bin_id', 'batch_no', 'serial_no'], 
                'unique_inventory_identifier'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventories');
    }
};
