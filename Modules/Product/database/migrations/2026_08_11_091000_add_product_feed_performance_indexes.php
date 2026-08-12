<?php

use App\Support\ConcurrentIndex;
use App\Support\SearchExpression;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    private const FTS_COLUMNS = ['name', 'sku'];

    private function structuralIndexes(): array
    {
        return [

            'idx_pv_product_sell_price' => ['product_variants', ['product_id', 'sell_price'],
                'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_pv_product_sell_price ON product_variants (product_id, sell_price)'],
            'idx_pmedia_product_id' => ['product_media', ['product_id'],
                'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_pmedia_product_id ON product_media (product_id)'],
            'idx_pmedia_variant_id' => ['product_media', ['variant_id'],
                'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_pmedia_variant_id ON product_media (variant_id)'],
            'idx_pvcm_variant_id' => ['product_variant_channel_mappings', ['variant_id'],
                'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_pvcm_variant_id ON product_variant_channel_mappings (variant_id)'],
            'idx_products_category_id' => ['products', ['category_id'],
                'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_products_category_id ON products (category_id)'],
            'idx_categories_parent_id' => ['categories', ['parent_id'],
                'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_categories_parent_id ON categories (parent_id)'],
            'idx_channel_shops_channel_id' => ['channel_shops', ['channel_id'],
                'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_channel_shops_channel_id ON channel_shops (channel_id)'],

            'idx_products_status_updated_at' => ['products', ['status', 'updated_at', 'deleted_at'],
                'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_products_status_updated_at ON products (status, updated_at DESC) WHERE deleted_at IS NULL'],
            'idx_products_status_created_at' => ['products', ['status', 'created_at', 'deleted_at'],
                'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_products_status_created_at ON products (status, created_at DESC) WHERE deleted_at IS NULL'],
            'idx_products_status_name' => ['products', ['status', 'name', 'deleted_at'],
                'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_products_status_name ON products (status, name) WHERE deleted_at IS NULL'],
            'idx_products_archived_at' => ['products', ['status', 'archived_at', 'deleted_at'],
                'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_products_archived_at ON products (status, archived_at DESC) WHERE deleted_at IS NULL'],

            'idx_products_from_channel' => ['products', ['is_from_channel', 'updated_at', 'deleted_at'],
                'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_products_from_channel ON products (updated_at DESC) WHERE deleted_at IS NULL AND is_from_channel = true'],
        ];
    }

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->structuralIndexes() as $name => [$table, $columns, $sql]) {
            ConcurrentIndex::create($name, $table, $columns, $sql);
        }

        $vector = SearchExpression::vector(self::FTS_COLUMNS);
        ConcurrentIndex::create(
            'idx_products_search_fts',
            'products',
            self::FTS_COLUMNS,
            "CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_products_search_fts ON products USING gin ({$vector})"
        );

        foreach (self::FTS_COLUMNS as $column) {
            $name = "idx_products_{$column}_trgm";
            $expression = SearchExpression::text($column);

            ConcurrentIndex::create(
                $name,
                'products',
                [$column],
                "CREATE INDEX CONCURRENTLY IF NOT EXISTS {$name} ON products USING gin (({$expression}) gin_trgm_ops)"
            );
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach (array_keys($this->structuralIndexes()) as $name) {
            ConcurrentIndex::drop($name);
        }

        ConcurrentIndex::drop('idx_products_search_fts');

        foreach (self::FTS_COLUMNS as $column) {
            ConcurrentIndex::drop("idx_products_{$column}_trgm");
        }
    }
};
