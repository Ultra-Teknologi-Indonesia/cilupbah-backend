<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_revaluations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('revaluation_no', 50)->unique();
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();
            $table->string('status', 30)->default('DRAFT');
            $table->text('notes')->nullable();
            $table->string('created_by', 100);
            $table->string('approved_by', 100)->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('stock_revaluation_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('stock_revaluation_id')->constrained('stock_revaluations')->cascadeOnDelete();
            $table->foreignUuid('item_id')->constrained('product_variants')->restrictOnDelete();
            $table->foreignUuid('bin_id')->nullable()->constrained('location_bins')->nullOnDelete();
            $table->integer('qty');
            $table->decimal('old_cost', 15, 2)->default(0);
            $table->decimal('new_cost', 15, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_revaluation_items');
        Schema::dropIfExists('stock_revaluations');
    }
};
