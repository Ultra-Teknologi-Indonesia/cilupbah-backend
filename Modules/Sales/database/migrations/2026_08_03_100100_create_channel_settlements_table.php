<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::create('channel_settlements', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('channel', 30);                 
            $table->string('shop_id');                     
            $table->string('external_id');                 
            $table->string('external_payment_id')->nullable(); 
            $table->string('type', 20)->default('STATEMENT');  

            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->timestamp('settled_at')->nullable();   
            $table->timestamp('paid_at')->nullable();      

            $table->string('payment_status', 20)->nullable(); 

            $table->decimal('total_settlement', 18, 4)->nullable();
            $table->decimal('total_fee', 18, 4)->nullable();
            $table->decimal('total_adjustment', 18, 4)->nullable();
            $table->string('currency', 8)->default('IDR');

            $table->jsonb('raw')->nullable();              
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['channel', 'shop_id', 'external_id']);
            $table->index(['channel', 'settled_at']);
            $table->index('payment_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_settlements');
    }
};
