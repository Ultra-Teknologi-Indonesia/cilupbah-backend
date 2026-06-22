<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_adjustments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('adjustment_no')->unique();
            $table->date('adjustment_date');
            $table->string('type')->default('online');
            $table->string('status')->default('draft');
            $table->text('notes')->nullable();
            $table->string('created_by')->nullable();
            $table->string('applied_by')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('type');
        });

        Schema::create('price_adjustment_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('price_adjustment_id')->constrained('price_adjustments')->cascadeOnDelete();
            $table->foreignUuid('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->foreignUuid('channel_shop_id')->nullable()->constrained('channel_shops')->restrictOnDelete();
            $table->foreignUuid('location_id')->nullable()->constrained('locations')->restrictOnDelete();
            $table->decimal('old_price', 15, 2)->default(0);
            $table->decimal('new_price', 15, 2)->default(0);
            $table->timestamps();

            $table->index('price_adjustment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_adjustment_items');
        Schema::dropIfExists('price_adjustments');
    }
};
