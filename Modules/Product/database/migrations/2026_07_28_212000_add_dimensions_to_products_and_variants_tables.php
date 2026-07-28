<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'length')) {
                $table->decimal('length', 10, 2)->default(0)->after('weight');
            }
            if (! Schema::hasColumn('products', 'width')) {
                $table->decimal('width', 10, 2)->default(0)->after('length');
            }
            if (! Schema::hasColumn('products', 'height')) {
                $table->decimal('height', 10, 2)->default(0)->after('width');
            }
        });

        Schema::table('product_variants', function (Blueprint $table) {
            if (! Schema::hasColumn('product_variants', 'length')) {
                $table->decimal('length', 10, 2)->default(0)->after('weight');
            }
            if (! Schema::hasColumn('product_variants', 'width')) {
                $table->decimal('width', 10, 2)->default(0)->after('length');
            }
            if (! Schema::hasColumn('product_variants', 'height')) {
                $table->decimal('height', 10, 2)->default(0)->after('width');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['length', 'width', 'height']);
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn(['length', 'width', 'height']);
        });
    }
};
