<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('download_transaction_products', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('download_transaction_id');
            $table->uuid('product_id');
            $table->string('external_product_id');
            $table->timestamps();

            $table->foreign('download_transaction_id')
                ->references('id')
                ->on('download_transactions')
                ->cascadeOnDelete();
            $table->foreign('product_id')
                ->references('id')
                ->on('products')
                ->cascadeOnDelete();
            $table->unique(
                ['download_transaction_id', 'product_id', 'external_product_id'],
                'download_transaction_products_unique',
            );
            $table->index(['download_transaction_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('download_transaction_products');
    }
};
