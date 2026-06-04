
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('item_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('location_id')->constrained('locations')->cascadeOnDelete();
            $table->foreignId('bin_id')->nullable()->constrained('location_bins')->nullOnDelete();
            
            $table->string('transaction_number', 100);
            $table->string('source', 50);
            
            $table->integer('qty');
            $table->integer('balance');
            
            $table->timestamp('transaction_date')->useCurrent();
            $table->string('created_by', 100);
            
            $table->timestamps();

            $table->index(['item_id', 'location_id', 'transaction_date']);
            $table->index('transaction_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
    }
};
