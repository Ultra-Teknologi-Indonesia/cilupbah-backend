<?php

namespace Modules\Inventory\Services\RackImport;

use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\RackImportBatch;
use Modules\Inventory\Models\SkuRackAssignment;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;
use Modules\Warehouse\Services\BinOccupancyGuard;
use Modules\Warehouse\Services\SkuHomeBinGuard;

class RackAllocationClassifier
{
    private const DEFAULT_BIN_CODE = 'DEFAULT';

    private array $locByName = [];

    private array $locByCode = [];

    public function __construct(
        private SkuHomeBinGuard $homeGuard,
        private BinOccupancyGuard $occupancyGuard,
    ) {
        $locations = Location::all(['id', 'location_name', 'location_code']);
        $this->locByName = $locations->keyBy(fn ($l) => mb_strtolower(trim((string) $l->location_name)))->all();
        $this->locByCode = $locations->keyBy(fn ($l) => mb_strtolower(trim((string) $l->location_code)))->all();
    }

    public function classify(array $rows): array
    {
        $maps = $this->preload($rows);

        $records = [];
        $counts = [
            RackImportBatch::STATUS_PLACE => 0,
            RackImportBatch::STATUS_MANUAL_MOVE => 0,
            RackImportBatch::STATUS_ALREADY => 0,
            RackImportBatch::STATUS_ERROR => 0,
        ];

        foreach ($rows as $row) {
            $record = $this->classifyRow($row, $maps);
            $counts[$record['status']] = ($counts[$record['status']] ?? 0) + 1;
            $records[] = $record;
        }

        return ['records' => $records, 'counts' => $counts];
    }

    private function classifyRow(array $row, array $maps): array
    {
        $sku = $row['sku'];
        $locName = $row['location'];
        $binCode = $row['bin'];

        $base = [
            'row_no' => $row['row_no'],
            'raw_sku' => $sku,
            'raw_location' => $locName,
            'raw_bin' => $binCode,
            'item_id' => null,
            'location_id' => null,
            'bin_id' => null,
            'product_name' => null,
            'current_bin' => null,
        ];

        $error = fn (string $message) => $base + ['status' => RackImportBatch::STATUS_ERROR, 'message' => $message];

        $variant = $maps['variants'][mb_strtolower($sku)] ?? null;
        if (! $variant) {
            return $error('SKU tidak terdaftar.');
        }
        $base['item_id'] = $variant->id;
        $base['product_name'] = $maps['names'][$variant->product_id] ?? null;

        $location = $this->locByName[mb_strtolower($locName)] ?? $this->locByCode[mb_strtolower($locName)] ?? null;
        if (! $location) {
            return $error("Lokasi \"{$locName}\" tidak ditemukan.");
        }
        $base['location_id'] = $location->id;

        $bin = $maps['bins'][$location->id . '|' . $binCode] ?? null;
        if (! $bin) {
            return $error("Rak \"{$binCode}\" tidak ada di lokasi ini.");
        }
        if ($bin->is_inbound) {
            return $error('Rak inbound tidak bisa dijadikan alokasi.');
        }
        $base['bin_id'] = $bin->id;

        $home = $maps['homeBins'][$location->id . '|' . $variant->id] ?? null;
        $homeBinId = $home['bin_id'] ?? null;

        if ($homeBinId === $bin->id) {
            return $base + ['status' => RackImportBatch::STATUS_ALREADY, 'message' => 'SKU sudah berada di rak ini.'];
        }

        if ($homeBinId !== null) {
            $currentCode = $home['bin_code'] ?? null;
            $base['current_bin'] = $currentCode;

            return $base + [
                'status' => RackImportBatch::STATUS_MANUAL_MOVE,
                'message' => "Stok sudah ada di rak {$currentCode}. Pindahkan ke {$binCode} lewat Pindah Bin secara manual.",
            ];
        }

        if (! $this->occupancyGuard->isBinFreeFor($bin->id, $variant->id)) {
            return $error('Rak sudah berisi SKU lain (gudang kecil = 1 rak 1 SKU).');
        }

        return $base + [
            'status' => RackImportBatch::STATUS_PLACE,
            'message' => 'Alokasi rak akan disimpan. Stok tidak dipindahkan oleh import ini.',
        ];
    }

    private function preload(array $rows): array
    {
        $skus = [];
        foreach ($rows as $r) {
            if ($r['sku'] !== '') {
                $skus[mb_strtolower($r['sku'])] = $r['sku'];
            }
        }
        $skus = array_values($skus);

        $variants = ProductVariant::whereIn('sku', $skus)
            ->get(['id', 'sku', 'product_id'])
            ->keyBy(fn ($v) => mb_strtolower((string) $v->sku));

        $itemIds = $variants->pluck('id')->all();
        $productIds = $variants->pluck('product_id')->filter()->unique()->values()->all();

        $names = Product::whereIn('id', $productIds)->pluck('name', 'id');

        $locationIds = [];
        foreach (array_merge($this->locByName, $this->locByCode) as $loc) {
            $locationIds[$loc->id] = true;
        }
        $locationIds = array_keys($locationIds);

        $binCodes = [];
        foreach ($rows as $r) {
            if ($r['bin'] !== '') {
                $binCodes[] = $r['bin'];
            }
        }
        $binCodes = array_values(array_unique($binCodes));

        $binsQuery = LocationBin::whereIn('location_id', $locationIds);
        if (!empty($binCodes)) {
            $binsQuery->whereIn('bin_final_code', $binCodes);
        }

        $bins = $binsQuery->get(['id', 'location_id', 'bin_final_code', 'is_inbound', 'is_stock_acknowledged'])
            ->keyBy(fn ($b) => $b->location_id . '|' . $b->bin_final_code);

        $homeBins = [];
        if (! empty($itemIds)) {
            DB::table('inventories')
                ->join('location_bins', 'location_bins.id', '=', 'inventories.bin_id')
                ->whereIn('inventories.location_id', $locationIds)
                ->whereIn('inventories.item_id', $itemIds)
                ->whereNotNull('inventories.bin_id')
                ->where(function ($w) {
                    $w->where('inventories.on_hand', '>', 0)->orWhere('inventories.on_order', '>', 0);
                })
                ->where('location_bins.is_inbound', false)
                ->where('location_bins.is_stock_acknowledged', true)
                ->where('location_bins.bin_final_code', '!=', self::DEFAULT_BIN_CODE)
                ->select('inventories.location_id', 'inventories.item_id', 'inventories.bin_id', 'location_bins.bin_final_code')
                ->orderBy('inventories.created_at')
                ->get()
                ->each(function ($r) use (&$homeBins) {
                    $key = $r->location_id . '|' . $r->item_id;
                    $homeBins[$key] ??= ['bin_id' => $r->bin_id, 'bin_code' => $r->bin_final_code];
                });
        }

        $binIds = $bins->pluck('id')->all();
        if (! empty($itemIds) || ! empty($binIds)) {
            SkuRackAssignment::query()
                ->join('location_bins', 'location_bins.id', '=', 'sku_rack_assignments.bin_id')
                ->whereIn('sku_rack_assignments.location_id', $locationIds)
                ->where(function ($w) use ($itemIds, $binIds) {
                    $w->whereIn('sku_rack_assignments.item_id', $itemIds)
                        ->orWhereIn('sku_rack_assignments.bin_id', $binIds);
                })
                ->get([
                    'sku_rack_assignments.location_id',
                    'sku_rack_assignments.item_id',
                    'sku_rack_assignments.bin_id',
                    'location_bins.bin_final_code',
                ])
                ->each(function ($r) use (&$homeBins) {
                    $homeBins[$r->location_id . '|' . $r->item_id] ??= ['bin_id' => $r->bin_id, 'bin_code' => $r->bin_final_code];
                });
        }

        return [
            'variants' => $variants,
            'names' => $names,
            'bins' => $bins,
            'homeBins' => $homeBins,
        ];
    }
}
