<?php

use App\Support\ConcurrentIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        try {
            DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        } catch (Throwable $e) {
            Log::warning('Monitor stock trigram indexes skipped: pg_trgm is unavailable.', [
                'error' => $e->getMessage(),
            ]);

            return;
        }

        ConcurrentIndex::create(
            'idx_product_variants_sku_lower_trgm',
            'product_variants',
            ['sku'],
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_product_variants_sku_lower_trgm ON product_variants USING gin (LOWER(sku) gin_trgm_ops)',
        );

        ConcurrentIndex::create(
            'idx_products_name_lower_trgm',
            'products',
            ['name'],
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_products_name_lower_trgm ON products USING gin (LOWER(name) gin_trgm_ops)',
        );
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        ConcurrentIndex::drop('idx_product_variants_sku_lower_trgm');
        ConcurrentIndex::drop('idx_products_name_lower_trgm');
    }
};
