<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracking_items', function (Blueprint $table) {
            $table->id();
            $table->string('domain')->index();              
            $table->string('method', 10)->nullable();        
            $table->string('endpoint');                      
            $table->text('function_id');                     
            $table->text('cilupbah_impl')->nullable();       
            $table->string('status', 12)->default('todo')->index();   
            $table->string('baseline_status', 12)->default('todo');   
            $table->string('pic')->nullable()->index();      
            $table->text('notes')->nullable();               
            $table->string('priority', 8)->nullable();       
            $table->string('source', 16)->default('jubelio');
            $table->string('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['method', 'endpoint']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracking_items');
    }
};
