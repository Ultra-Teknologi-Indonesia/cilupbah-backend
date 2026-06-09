<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->decimal('tax_rate', 5, 2)->default(0)->after('sell_price');
            $table->boolean('is_internal')->nullable()->after('is_active');
            $table->integer('sequence_item')->nullable()->after('is_internal');
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn(['tax_rate', 'is_internal', 'sequence_item']);
        });
    }
};
