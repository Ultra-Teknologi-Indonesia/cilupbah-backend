<?php

use App\Support\ConcurrentIndex;
use App\Support\SearchExpression;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    private const TARGETS = [
        'sales_orders' => [
            'fts' => [['channel_shop_id']],
            'trgm' => ['channel_shop_id'],
        ],
        'picklist_items' => [
            'fts' => [['sku']],
            'trgm' => ['sku'],
        ],
        'channel_shops' => [
            'fts' => [['shop_name']],
            'trgm' => ['shop_name'],
        ],
        'users' => [
            'fts' => [['name']],
            'trgm' => ['name'],
        ],
    ];

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::TARGETS as $table => $spec) {
            foreach ($spec['fts'] as $index => $columns) {
                $name = $this->ftsName($table, $index);
                $vector = SearchExpression::vector($columns);

                ConcurrentIndex::create(
                    $name,
                    $table,
                    $columns,
                    "CREATE INDEX CONCURRENTLY IF NOT EXISTS {$name} ON {$table} USING gin ({$vector})",
                );
            }

            foreach ($spec['trgm'] as $column) {
                $name = $this->trigramName($table, $column);
                $expression = SearchExpression::text($column);

                ConcurrentIndex::create(
                    $name,
                    $table,
                    [$column],
                    "CREATE INDEX CONCURRENTLY IF NOT EXISTS {$name} ON {$table} USING gin (({$expression}) gin_trgm_ops)",
                );
            }
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::TARGETS as $table => $spec) {
            foreach (array_keys($spec['fts']) as $index) {
                ConcurrentIndex::drop($this->ftsName($table, $index));
            }

            foreach ($spec['trgm'] as $column) {
                ConcurrentIndex::drop($this->trigramName($table, $column));
            }
        }
    }

    private function ftsName(string $table, int $index): string
    {
        return 'idx_'.$this->abbreviateTable($table).'_nested_search_fts_'.($index + 1);
    }

    private function trigramName(string $table, string $column): string
    {
        return 'idx_'.$this->abbreviateTable($table).'_'.$column.'_nested_trgm';
    }

    private function abbreviateTable(string $table): string
    {
        return match ($table) {
            'sales_orders' => 'so',
            default => $table,
        };
    }
};
