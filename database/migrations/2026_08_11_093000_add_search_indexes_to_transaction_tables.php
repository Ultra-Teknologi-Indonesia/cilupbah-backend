<?php

use App\Support\ConcurrentIndex;
use App\Support\SearchExpression;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    private const TARGETS = [
        'inbounds' => [
            'fts' => [['transaction_number', 'reference_number']],
            'trgm' => ['transaction_number', 'reference_number'],
        ],
        'sales_invoices' => [
            'fts' => [['invoice_number', 'customer_name']],
            'trgm' => ['invoice_number', 'customer_name'],
        ],
        'sales_returns' => [
            'fts' => [['return_number', 'customer_name', 'return_tracking_number']],
            'trgm' => ['return_number', 'customer_name', 'return_tracking_number'],
        ],
        'sales_payments' => [
            'fts' => [['payment_number', 'reference_no']],
            'trgm' => ['payment_number', 'reference_no'],
        ],
        'sales_order_items' => [
            'fts' => [['sku', 'description']],
            'trgm' => ['sku', 'description'],
        ],
        'shipments' => [
            'fts' => [['shipment_no'], ['tracking_number']],
            'trgm' => ['shipment_no', 'tracking_number'],
        ],
        'picklists' => [
            'fts' => [['picklist_no']],
            'trgm' => ['picklist_no'],
        ],
        'packlists' => [
            'fts' => [['packlist_no']],
            'trgm' => ['packlist_no'],
        ],
        'product_variants' => [
            'fts' => [['sku']],
            'trgm' => ['sku'],
        ],

        'journals' => [
            'fts' => [['journal_no', 'source_doc_no', 'notes']],
            'trgm' => ['journal_no', 'source_doc_no'],
        ],
    ];

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::TARGETS as $table => $spec) {
            foreach ($spec['fts'] as $i => $columns) {
                $name = $this->ftsName($table, $i);

                ConcurrentIndex::create(
                    $name,
                    $table,
                    $columns,
                    "CREATE INDEX CONCURRENTLY IF NOT EXISTS {$name} ON {$table} USING gin (".SearchExpression::vector($columns).')'
                );
            }

            foreach ($spec['trgm'] as $column) {
                $name = $this->trigramName($table, $column);
                $expression = SearchExpression::text($column);

                ConcurrentIndex::create(
                    $name,
                    $table,
                    [$column],
                    "CREATE INDEX CONCURRENTLY IF NOT EXISTS {$name} ON {$table} USING gin (({$expression}) gin_trgm_ops)"
                );
            }
        }

        ConcurrentIndex::drop('idx_so_search_fts_1');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::TARGETS as $table => $spec) {
            foreach (array_keys($spec['fts']) as $i) {
                ConcurrentIndex::drop($this->ftsName($table, $i));
            }

            foreach ($spec['trgm'] as $column) {
                ConcurrentIndex::drop($this->trigramName($table, $column));
            }
        }
    }

    private function ftsName(string $table, int $index): string
    {
        return 'idx_'.$this->abbrev($table).'_search_fts_'.($index + 1);
    }

    private function trigramName(string $table, string $column): string
    {
        return 'idx_'.$this->abbrev($table).'_'.$column.'_trgm';
    }

    private function abbrev(string $table): string
    {
        return strlen($table) <= 16
            ? $table
            : implode('', array_map(fn (string $part) => substr($part, 0, 3), explode('_', $table)));
    }
};
