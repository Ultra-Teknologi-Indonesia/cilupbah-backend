<?php

declare(strict_types=1);

namespace Modules\Product\Exports;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithCustomChunkSize;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Modules\Inventory\Support\StockSummary;
use Modules\Product\Repositories\MasterFeedRepository;
use Modules\Product\Support\TechnicalSku;

final class ProductCatalogCsvExport implements FromQuery, WithCustomChunkSize, WithCustomCsvSettings, WithHeadings, WithMapping
{
    private ?bool $hasRepresentativeColumn = null;

    public function __construct(private readonly array $filters = []) {}

    public function query(): Builder
    {
        $normal = $this->normalVariantQuery();
        $bundle = $this->bundleVariantQuery();

        return DB::query()
            ->fromSub($normal->unionAll($bundle), 'catalog_rows')
            ->orderBy('item_group_id')
            ->orderBy('item_id');
    }

    public function headings(): array
    {
        return [
            'Item ID',
            'Item Group ID',
            'Name',
            'SKU',
            'Category Name',
            'Variation',
            'Description',
            'Package Weight',
            'Package Width',
            'Package Height',
            'Package Length',
            'Sell Price',
            'Image 1',
            'Image 2',
            'Image 3',
            'Image 4',
            'Image 5',
            'Stock',
        ];
    }

    public function map($row): array
    {
        return [
            $row->item_id,
            $row->item_group_id,
            $row->name ?? '',
            $row->sku ?? '',
            $row->category_name ?? '',
            $row->variation ?? '',
            $row->description ?? '',
            $this->numberOrZero($row->package_weight),
            $this->numberOrZero($row->package_width),
            $this->numberOrZero($row->package_height),
            $this->numberOrZero($row->package_length),
            $this->numberOrZero($row->sell_price),
            $row->image_1 ?? '',
            $row->image_2 ?? '',
            $row->image_3 ?? '',
            $row->image_4 ?? '',
            $row->image_5 ?? '',
            $this->numberOrZero($row->stock),
        ];
    }

    public function getCsvSettings(): array
    {
        return [
            'delimiter' => ',',
            'enclosure' => '"',
            'line_ending' => "\r\n",
            'use_bom' => false,
        ];
    }

    public function chunkSize(): int
    {
        return (int) config('exports.catalog_chunk_size', 250);
    }

    private function normalVariantQuery(): Builder
    {
        $query = $this->baseQuery()
            ->join('product_variants as v', function (JoinClause $join): void {
                $join->on('v.product_id', '=', 'p.id');
                if ($this->hasRepresentativeColumn()) {
                    $join->orWhereExists(function ($sub): void {
                        $sub->select(DB::raw(1))
                            ->from('product_merges as member_merge')
                            ->whereColumn('member_merge.product_id', 'v.product_id')
                            ->whereColumn('member_merge.master_name', 'rep_merge.master_name');
                    });
                }
            })
            ->where('p.is_bundle', false)
            ->whereNull('v.deleted_at')
            ->where(function ($scope) {
                TechnicalSku::exclude($scope, 'v.sku');
            })
            ->select($this->columns('v.id', 'v.sku'))
            ->selectRaw('v.sell_price')
            ->selectRaw('p.weight as package_weight')
            ->selectRaw('p.width as package_width')
            ->selectRaw('p.height as package_height')
            ->selectRaw('p.length as package_length');

        $this->joinVariantData($query);

        return $this->applyFilters($query, false);
    }

    private function bundleVariantQuery(): Builder
    {
        $query = $this->baseQuery()
            ->join('product_bundle_items as bi', function (JoinClause $join): void {
                $join->on('bi.bundle_product_id', '=', 'p.id');
                if ($this->hasRepresentativeColumn()) {
                    $join->orWhereExists(function ($sub): void {
                        $sub->select(DB::raw(1))
                            ->from('product_merges as member_merge')
                            ->whereColumn('member_merge.product_id', 'bi.bundle_product_id')
                            ->whereColumn('member_merge.master_name', 'rep_merge.master_name');
                    });
                }
            })
            ->join('product_variants as v', 'v.id', '=', 'bi.component_variant_id')
            ->where('p.is_bundle', true)
            ->whereNull('v.deleted_at')
            ->where(function ($scope) {
                TechnicalSku::exclude($scope, 'v.sku');
            })
            ->select($this->columns('v.id', 'v.sku'))
            ->selectRaw('v.sell_price')
            ->selectRaw('p.weight as package_weight')
            ->selectRaw('p.width as package_width')
            ->selectRaw('p.height as package_height')
            ->selectRaw('p.length as package_length');

        $this->joinVariantData($query);

        return $this->applyFilters($query, true);
    }

    private function baseQuery(): Builder
    {
        $query = DB::table('products as p')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->where('p.status', $this->filters['status'] ?? 'master')
            ->whereNull('p.deleted_at');

        if ($this->hasRepresentativeColumn()) {
            $query->leftJoin('product_merges as rep_merge', function (JoinClause $join): void {
                $join->on('rep_merge.product_id', '=', 'p.id')
                    ->where('rep_merge.is_representative', true);
            });
            $query->whereNotExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('product_merges')
                    ->whereColumn('product_merges.product_id', 'p.id')
                    ->where('product_merges.is_representative', false);
            });
        } else {
            $query->whereNotExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('product_merges as pm1')
                    ->join('product_merges as pm2', 'pm1.master_name', '=', 'pm2.master_name')
                    ->whereColumn('pm1.product_id', 'p.id')
                    ->whereRaw('pm2.product_id < pm1.product_id');
            });
        }

        if (! empty($this->filters['product_ids'])) {
            $query->whereIn('p.id', $this->filters['product_ids']);
        }

        return $query;
    }

    private function hasRepresentativeColumn(): bool
    {
        return $this->hasRepresentativeColumn ??= MasterFeedRepository::hasRepresentativeColumn();
    }

    private function applyFilters(Builder $query, bool $bundle): Builder
    {
        $search = trim((string) ($this->filters['search'] ?? ''));
        if ($search !== '') {
            $like = "%{$search}%";
            $query->where(function ($scope) use ($like, $bundle) {
                $scope->whereRaw(
                    $this->hasRepresentativeColumn()
                        ? 'COALESCE(rep_merge.master_name, p.name) ILIKE ?'
                        : 'p.name ILIKE ?',
                    [$like],
                )
                    ->orWhere('p.sku', 'ilike', $like)
                    ->orWhereExists(function ($sub) use ($like, $bundle) {
                        $sub->select(DB::raw(1))
                            ->from('product_variants as search_v')
                            ->when($bundle, function ($q) {
                                $q->join('product_bundle_items as search_bi', 'search_bi.component_variant_id', '=', 'search_v.id')
                                    ->whereColumn('search_bi.bundle_product_id', 'p.id');
                            }, function ($q) {
                                $q->whereColumn('search_v.product_id', 'p.id');
                            })
                            ->whereNull('search_v.deleted_at')
                            ->where('search_v.sku', 'ilike', $like);
                    });
            });
            $query->whereRaw('LEFT(LOWER(COALESCE(p.sku, \'\')), ?) <> ?', [strlen(TechnicalSku::BUNDLE_PREFIX), TechnicalSku::BUNDLE_PREFIX]);
        }

        $categoryIds = $this->filters['category_id'] ?? null;
        if ($categoryIds !== null && $categoryIds !== '') {
            $ids = is_array($categoryIds) ? $categoryIds : explode(',', (string) $categoryIds);
            $query->whereIn('p.category_id', $this->categoryIdsWithDescendants($ids));
        }

        match ($this->filters['type'] ?? null) {
            'bundle' => $query->where('p.is_bundle', true),
            'konsinyasi' => $query->where('p.is_consignment', true),
            'pre_order' => $query->where('p.order_type', 'PREORDER'),
            'satuan' => $query->where('p.is_bundle', false)
                ->where('p.is_consignment', false)
                ->where(fn ($q) => $q->where('p.order_type', '<>', 'PREORDER')->orWhereNull('p.order_type')),
            default => null,
        };

        foreach (['min_price' => '>=', 'max_price' => '<='] as $filter => $operator) {
            if (($value = $this->filters[$filter] ?? null) !== null && $value !== '') {
                $query->where('v.sell_price', $operator, $value);
            }
        }

        if (! empty($this->filters['channel'])) {
            $query->whereExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('product_channel_mappings as pcm')
                    ->join('channel_shops as cs', 'cs.id', '=', 'pcm.channel_shop_id')
                    ->join('channels as ch', 'ch.id', '=', 'cs.channel_id')
                    ->whereColumn('pcm.product_id', 'p.id')
                    ->where('ch.code', $this->filters['channel']);
            });
        }

        return $query;
    }

    private function columns(string $itemId, string $sku): array
    {
        return [
            DB::raw("{$itemId} as item_id"),
            'p.id as item_group_id',
            DB::raw($this->hasRepresentativeColumn()
                ? 'COALESCE(rep_merge.master_name, p.name) as name'
                : 'p.name'),
            DB::raw("{$sku} as sku"),
            'c.name as category_name',
            'p.description',
        ];
    }

    private function joinVariantData(Builder $query): void
    {
        $options = DB::table('variant_options as vo')
            ->groupBy('vo.variant_id')
            ->select('vo.variant_id')
            ->selectRaw("STRING_AGG(vo.value, ', ' ORDER BY vo.id) as variation");

        $stock = DB::table('inventories as si')
            ->leftJoin('location_bins as sb', 'sb.id', '=', 'si.bin_id')
            ->groupBy('si.item_id')
            ->select('si.item_id')
            ->selectRaw(
                StockSummary::placedOnHandSql('si', 'sb').' - '.StockSummary::onOrderSql('si').' as stock'
            );

        $variantImages = DB::table('product_media as vm')
            ->where('vm.media_type', 'image')
            ->whereNotNull('vm.variant_id')
            ->groupBy('vm.variant_id')
            ->select('vm.variant_id')
            ->selectRaw('ARRAY_AGG(vm.url ORDER BY vm.is_primary DESC, vm.sort_order ASC, vm.id ASC) as urls');

        $productImages = DB::table('product_media as pm')
            ->where('pm.media_type', 'image')
            ->whereNull('pm.variant_id')
            ->groupBy('pm.product_id')
            ->select('pm.product_id')
            ->selectRaw('ARRAY_AGG(pm.url ORDER BY pm.is_primary DESC, pm.sort_order ASC, pm.id ASC) as urls');

        $query
            ->leftJoinSub($options, 'catalog_options', 'catalog_options.variant_id', '=', 'v.id')
            ->leftJoinSub($stock, 'catalog_stock', 'catalog_stock.item_id', '=', 'v.id')
            ->leftJoinSub($variantImages, 'catalog_variant_images', 'catalog_variant_images.variant_id', '=', 'v.id')
            ->leftJoinSub($productImages, 'catalog_product_images', 'catalog_product_images.product_id', '=', 'p.id')
            ->addSelect([
                'catalog_options.variation',
                DB::raw('COALESCE(catalog_variant_images.urls[1], catalog_product_images.urls[1]) as image_1'),
                DB::raw('COALESCE(catalog_variant_images.urls[2], catalog_product_images.urls[2]) as image_2'),
                DB::raw('COALESCE(catalog_variant_images.urls[3], catalog_product_images.urls[3]) as image_3'),
                DB::raw('COALESCE(catalog_variant_images.urls[4], catalog_product_images.urls[4]) as image_4'),
                DB::raw('COALESCE(catalog_variant_images.urls[5], catalog_product_images.urls[5]) as image_5'),
                DB::raw('COALESCE(catalog_stock.stock, 0) as stock'),
            ]);
    }

    private function categoryIdsWithDescendants(array $values): array
    {
        $roots = array_values(array_unique(array_filter(array_map('intval', $values))));
        if ($roots === []) {
            return [0];
        }

        $placeholders = implode(',', array_fill(0, count($roots), '?'));
        $rows = DB::select(
            "WITH RECURSIVE category_tree AS (
                SELECT id FROM categories WHERE id IN ({$placeholders})
                UNION
                SELECT c.id FROM categories c JOIN category_tree t ON c.parent_id = t.id
            ) SELECT id FROM category_tree",
            $roots,
        );

        return $rows === [] ? [0] : array_map(static fn ($row): int => (int) $row->id, $rows);
    }

    private function numberOrZero(mixed $value): int|float
    {
        if ($value === null || $value === '') {
            return 0;
        }

        return str_contains((string) $value, '.') ? (float) $value : (int) $value;
    }
}
