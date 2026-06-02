<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained();
            $table->foreignId('brand_id')->nullable()->constrained();
            $table->unsignedBigInteger('showcase_id')->nullable();
            $table->string('name')->index();
            $table->string('sku')->unique()->nullable();
            $table->text('description')->nullable();
            $table->text('search_keyword')->nullable();
            $table->enum('order_type', ['REGULER', 'PREORDER', 'COD'])->default('REGULER');
            $table->integer('indent_days')->nullable();
            $table->decimal('weight', 10, 2)->default(0);
            $table->decimal('length', 10, 2)->default(0);
            $table->decimal('width', 10, 2)->default(0);
            $table->decimal('height', 10, 2)->default(0);
            $table->enum('condition', ['NEW', 'USED'])->default('NEW');
            $table->boolean('is_cod_allowed')->default(false);
            $table->integer('danger_level')->default(0);
            $table->boolean('is_draft')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
