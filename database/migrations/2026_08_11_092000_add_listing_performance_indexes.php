<?php

use App\Support\ConcurrentIndex;
use App\Support\SearchExpression;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Cross-module listing indexes.
 *
 * PostgreSQL does not index a column just because it carries a REFERENCES
 * constraint, so most detail-line tables have no index on the parent key they
 * are eager loaded by. Every `with('items')` on a paginated parent list then
 * costs a sequential scan of the whole detail table — invisible on seed data,
 * dominant in production and worse the larger ?per_page gets.
 *
 * Also adds the full-text and trigram indexes backing ?search= on sales_orders,
 * built from the same SearchExpression helper the `allowedSearch` macro uses so
 * the expressions cannot drift apart.
 *
 * Statements are guarded by schema checks: a table or column that does not exist
 * on this deployment is skipped and logged rather than failing the migration.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    /**
     * table => [[index name, columns...], ...]
     */
    private const PARENT_KEYS = [
        // Detail lines eager loaded from a paginated parent list.
        'inbound_items' => [['idx_inbound_items_inbound_id', 'inbound_id']],
        'sales_invoice_items' => [['idx_sii_sales_invoice_id', 'sales_invoice_id']],
        'sales_return_items' => [['idx_sri_sales_return_id', 'sales_return_id']],
        'purchase_order_items' => [['idx_poi_purchase_order_id', 'purchase_order_id']],
        'purchase_bill_items' => [['idx_pbi_purchase_bill_id', 'purchase_bill_id']],
        'purchase_return_items' => [['idx_pri_purchase_return_id', 'purchase_return_id']],
        'inventory_transfer_items' => [['idx_iti_inventory_transfer_id', 'inventory_transfer_id']],
        'stock_revaluation_items' => [['idx_sreval_items_parent_id', 'stock_revaluation_id']],
        'stock_replenishment_request_items' => [['idx_srri_request_id', 'request_id']],
        'picklist_items' => [['idx_picklist_items_order_item_id', 'order_item_id']],
        'shipment_orders' => [
            ['idx_shipment_orders_order_id', 'order_id'],
            ['idx_shipment_orders_packlist_id', 'packlist_id'],
        ],
        'product_wholesale_prices' => [['idx_pwp_variant_id', 'variant_id']],
        'product_specifications' => [['idx_pspec_product_id', 'product_id']],
        'issue_tracker_attachments' => [['idx_ita_issue_id', 'issue_id']],
        'variant_unlimited_shops' => [['idx_vus_channel_shop_id', 'channel_shop_id']],

        // Join keys used by list filters and lookups.
        'inventories' => [
            ['idx_inventories_location_id', 'location_id'],
            ['idx_inventories_bin_id', 'bin_id'],
        ],
        'location_bins' => [['idx_location_bins_zone_id', 'zone_id']],
        'attribute_options' => [['idx_attribute_options_attribute_id', 'attribute_id']],
        'variant_options' => [['idx_variant_options_attribute_id', 'attribute_id']],
        'category_attributes' => [['idx_category_attributes_attribute_id', 'attribute_id']],
        'cities' => [['idx_cities_province_id', 'province_id']],
        'districts' => [['idx_districts_city_id', 'city_id']],
        'villages' => [['idx_villages_district_id', 'district_id']],
    ];

    /**
     * Column sets passed to allowedSearch() on the sales order feeds.
     */
    private const SALES_ORDER_SEARCH_SETS = [
        ['salesorder_no', 'customer_name'],
        ['salesorder_no', 'channel_order_no', 'customer_name', 'tracking_number'],
    ];

    private const SALES_ORDER_TRIGRAM_COLUMNS = [
        'salesorder_no',
        'customer_name',
        'channel_order_no',
        'tracking_number',
    ];

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::PARENT_KEYS as $table => $indexes) {
            foreach ($indexes as [$name, $column]) {
                ConcurrentIndex::create(
                    $name,
                    $table,
                    [$column],
                    "CREATE INDEX CONCURRENTLY IF NOT EXISTS {$name} ON {$table} ({$column})"
                );
            }
        }

        $this->createSalesOrderSearchIndexes();
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->allIndexNames() as $name) {
            ConcurrentIndex::drop($name);
        }
    }

    private function createSalesOrderSearchIndexes(): void
    {
        foreach (self::SALES_ORDER_SEARCH_SETS as $i => $columns) {
            $name = 'idx_so_search_fts_'.($i + 1);
            $vector = SearchExpression::vector($columns);

            ConcurrentIndex::create(
                $name,
                'sales_orders',
                $columns,
                "CREATE INDEX CONCURRENTLY IF NOT EXISTS {$name} ON sales_orders USING gin ({$vector})"
            );
        }

        // The substring half of the search is OR-ed with the full-text half, so
        // it needs its own index or PostgreSQL falls back to a sequential scan
        // for the whole predicate.
        foreach (self::SALES_ORDER_TRIGRAM_COLUMNS as $column) {
            $name = "idx_so_{$column}_trgm";
            $expression = SearchExpression::text($column);

            ConcurrentIndex::create(
                $name,
                'sales_orders',
                [$column],
                "CREATE INDEX CONCURRENTLY IF NOT EXISTS {$name} ON sales_orders USING gin (({$expression}) gin_trgm_ops)"
            );
        }
    }

    /**
     * @return array<int, string>
     */
    private function allIndexNames(): array
    {
        $names = [];

        foreach (self::PARENT_KEYS as $indexes) {
            foreach ($indexes as [$name]) {
                $names[] = $name;
            }
        }

        foreach (array_keys(self::SALES_ORDER_SEARCH_SETS) as $i) {
            $names[] = 'idx_so_search_fts_'.($i + 1);
        }

        foreach (self::SALES_ORDER_TRIGRAM_COLUMNS as $column) {
            $names[] = "idx_so_{$column}_trgm";
        }

        return $names;
    }
};
