<?php

namespace Modules\Warehouse\Services;

use App\Traits\StockLockable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Inventory\Jobs\ProcessStockAdjustmentJob;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Models\SkuRackAssignment;
use Modules\Inventory\Repositories\InventoryMovementRepository;
use Modules\Inventory\Repositories\InventoryRepository;
use Modules\Inventory\Services\InventoryService;
use Modules\Inventory\Services\StockAdjustmentService;
use Modules\Product\Models\ProductVariant;
use Modules\Product\Support\TechnicalSku;
use Modules\Warehouse\Models\LocationBin;
use Modules\Warehouse\Models\LocationZone;
use Modules\Warehouse\Repositories\LocationBinRepository;
use Modules\Warehouse\Repositories\LocationRepository;

class LocationBinService
{
    use StockLockable;

    public function __construct(
        protected LocationBinRepository $binRepository,
        protected LocationRepository $locationRepository
    ) {}

    public function getByLocation(string $locationId): Collection
    {
        return $this->binRepository->findByLocation($locationId);
    }

    public function getByLocationPaginated(string $locationId)
    {
        return $this->binRepository->findByLocationPaginated($locationId);
    }

    public function getById(string $id): ?LocationBin
    {
        return $this->binRepository->findById($id);
    }

    public function getDefaultBin(string $locationId): ?LocationBin
    {
        return $this->binRepository->getDefaultBin($locationId);
    }

    public function create(array $data): LocationBin
    {
        $data['bin_final_code'] = $this->generateFinalCode($data);

        return $this->binRepository->create($data);
    }

    public function update(string $id, array $data): bool
    {
        $bin = $this->binRepository->findById($id);
        if (! $bin) {
            return false;
        }

        $merged = array_merge(
            $bin->only(['floor_code', 'row_code', 'column_code', 'bin_code']),
            $data
        );
        $data['bin_final_code'] = $this->generateFinalCode($merged);

        return $this->binRepository->update($id, $data);
    }

    public function delete(string $id): bool
    {
        $bin = $this->binRepository->findById($id);
        if (! $bin) {
            return false;
        }

        if ($bin->is_inbound) {
            throw new \DomainException('Bin inbound (default) tidak dapat dihapus.');
        }

        if ($this->binRepository->hasActiveStock($id)) {
            throw new \DomainException('Bin tidak dapat dihapus karena masih menyimpan stok.');
        }

        try {
            return $this->binRepository->delete($id);
        } catch (QueryException $e) {
            throw new \DomainException('Bin tidak dapat dihapus karena masih dipakai oleh transaksi lain.');
        }
    }

    public function massGenerate(string $locationId, array $data): array
    {
        if (! $this->locationRepository->exists($locationId)) {
            throw new ModelNotFoundException('Lokasi tidak ditemukan.');
        }

        $zoneCode = trim((string) ($data['zone_code'] ?? ''));

        return DB::transaction(function () use ($locationId, $data, $zoneCode) {
            $zoneId = null;
            if ($zoneCode !== '') {
                $zone = LocationZone::firstOrCreate(
                    ['location_id' => $locationId, 'zone_code' => $zoneCode],
                    ['zone_name' => null]
                );
                $zoneId = $zone->id;
            }

            $created = 0;

            for ($r = 1; $r <= $data['qty_row']; $r++) {
                for ($c = 1; $c <= $data['qty_column']; $c++) {
                    for ($b = 1; $b <= $data['qty_bin']; $b++) {
                        $codes = [
                            'floor_code' => $zoneCode,
                            'row_code' => "{$data['row_code']}{$r}",
                            'column_code' => "{$data['column_code']}{$c}",
                            'bin_code' => "{$data['bin_code']}{$b}",
                        ];

                        $finalCode = $this->generateFinalCode($codes);

                        [, $isNew] = $this->binRepository->firstOrCreateByFinalCode(
                            $locationId,
                            $finalCode,
                            array_merge($codes, [
                                'zone_id' => $zoneId,
                                'is_inbound' => false,
                                'is_stock_acknowledged' => true,
                                'is_large_bin' => false,
                                'category' => null,
                            ])
                        );

                        if ($isNew) {
                            $created++;
                        }
                    }
                }
            }

            return ['generated_count' => $created];
        });
    }

    protected function generateFinalCode(array $data): string
    {
        $parts = array_filter([
            $data['floor_code'] ?? null,
            $data['row_code'] ?? null,
            $data['column_code'] ?? null,
            $data['bin_code'] ?? null,
        ]);

        return ! empty($parts) ? implode('-', $parts) : 'DEFAULT';
    }

    public function bulkUpdate(string $locationId, array $bins): int
    {
        return DB::transaction(function () use ($locationId, $bins) {
            $updated = 0;
            foreach ($bins as $binData) {
                $payload = [
                    'is_stock_acknowledged' => $binData['is_stock_acknowledged'],
                    'is_large_bin' => $binData['is_large_bin'],
                    'category' => $binData['category'] ?? null,
                ];

                if (array_key_exists('bin_final_code', $binData)) {
                    $payload['bin_final_code'] = $binData['bin_final_code'];
                }

                $affected = LocationBin::where('location_id', $locationId)
                    ->where('id', $binData['id'])
                    ->update($payload);
                $updated += $affected;
            }

            return $updated;
        });
    }

    public function previewMassGenerate(array $data, int $page = 1, int $perPage = 50): array
    {
        $qtyRow = (int) $data['qty_row'];
        $qtyColumn = (int) $data['qty_column'];
        $qtyBin = (int) $data['qty_bin'];

        $total = $qtyRow * $qtyColumn * $qtyBin;
        $perPage = max(1, min($perPage, 1000));
        $page = max(1, $page);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $offset = ($page - 1) * $perPage;

        $items = [];
        if ($offset < $total) {
            $end = min($offset + $perPage, $total);
            for ($i = $offset; $i < $end; $i++) {
                $items[] = $this->buildPreviewRowAtIndex($i, $data);
            }
        }

        return [
            'data' => $items,
            'meta' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
            ],
        ];
    }

    protected function buildPreviewRowAtIndex(int $index, array $data): array
    {
        $qtyColumn = (int) $data['qty_column'];
        $qtyBin = (int) $data['qty_bin'];

        $perRow = $qtyColumn * $qtyBin;
        $perColumn = $qtyBin;

        $r = intdiv($index, $perRow) + 1;
        $rem = $index % $perRow;
        $c = intdiv($rem, $perColumn) + 1;
        $b = ($rem % $perColumn) + 1;

        $codes = [
            'floor_code' => trim((string) ($data['zone_code'] ?? '')),
            'row_code' => "{$data['row_code']}{$r}",
            'column_code' => "{$data['column_code']}{$c}",
            'bin_code' => "{$data['bin_code']}{$b}",
        ];

        return array_merge($codes, [
            'bin_final_code' => $this->generateFinalCode($codes),
        ]);
    }

    public function uniformApply(string $locationId, array $payload): int
    {
        $scope = $payload['scope'];
        $values = $payload['values'];

        $updateData = array_filter([
            'is_stock_acknowledged' => array_key_exists('is_stock_acknowledged', $values) ? (bool) $values['is_stock_acknowledged'] : null,
            'is_large_bin' => array_key_exists('is_large_bin', $values) ? (bool) $values['is_large_bin'] : null,
            'category' => array_key_exists('category', $values) ? $values['category'] : null,
            'zone_id' => array_key_exists('zone_id', $values) ? $values['zone_id'] : null,
        ], fn ($v) => $v !== null);

        if (empty($updateData)) {
            return 0;
        }

        if ($scope === 'selected') {
            return $this->binRepository->updateManyByIds(
                $locationId,
                $payload['ids'] ?? [],
                $updateData
            );
        }

        $query = $this->binRepository->applyFilterQuery($locationId);

        return $query->update($updateData);
    }

    public function getEligibleSkusForBin(string $locationId, string $binId, ?string $search = null, int $perPage = 20, int $page = 1)
    {
        $occupancyGuard = app(BinOccupancyGuard::class);
        $occupantId = $occupancyGuard->currentOccupantItemId($binId);

        $bin = $this->binRepository->findById($binId);
        $location = $this->locationRepository->find($locationId);

        $isGuarded = false;
        if ($bin && $location && $location->enforcesStrictBinSku()) {
            $isGuarded = ! $bin->is_inbound && $bin->is_stock_acknowledged && ! app(BinMultiSkuRuleService::class)->allowsMultiSku($bin);
        }

        $query = TechnicalSku::exclude(ProductVariant::with(['media', 'product:id,name', 'product.media']));

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('sku', 'ilike', "%{$search}%")
                    ->orWhereHas('product', function ($q2) use ($search) {
                        $q2->where('name', 'ilike', "%{$search}%");
                    });
            });
        }

        if ($isGuarded && $occupantId) {
            $query->where('id', $occupantId);
        } elseif ($location && $location->enforcesStrictBinSku()) {
            $query->whereDoesntHave('inventories', function ($q) use ($locationId, $binId) {
                $q->where('location_id', $locationId)
                    ->whereNotNull('bin_id')
                    ->where('bin_id', '!=', $binId)
                    ->where(function ($w) {
                        $w->where('on_hand', '>', 0)->orWhere('on_order', '>', 0);
                    })
                    ->whereHas('bin', function ($q2) {
                        $q2->where('is_inbound', false)
                            ->where('is_stock_acknowledged', true)
                            ->where('bin_final_code', '!=', SkuHomeBinGuard::DEFAULT_BIN_CODE);
                    });
            });

            $query->whereNotIn('id', function ($q) use ($locationId, $binId) {
                $q->select('item_id')
                    ->from('sku_rack_assignments')
                    ->where('location_id', $locationId)
                    ->where('bin_id', '!=', $binId);
            });
        }

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        $paginator->getCollection()->transform(function ($variant) {
            return [
                'variant_id' => $variant->id,
                'sku' => $variant->sku,
                'name' => $variant->product?->name,
                'thumbnail' => $variant->media->first()?->url ?? $variant->product?->media->first()?->url,
                'pending_qty' => 0,
            ];
        });

        return $paginator;
    }

    public function getPendingPutawaySkus(string $locationId, ?string $search = null, int $limit = 200): array
    {
        $location = $this->locationRepository->find($locationId);
        if (! $location || ! $location->enforcesStrictBinSku()) {
            return [];
        }

        $rows = Inventory::query()
            ->pendingPlacement()
            ->where('inventories.location_id', $locationId)
            ->where('inventories.on_hand', '>', 0)
            ->selectRaw('inventories.item_id as item_id, SUM(inventories.on_hand) as pending_qty')
            ->groupBy('inventories.item_id')
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $variants = TechnicalSku::exclude(ProductVariant::with(['media', 'product:id,name', 'product.media']))
            ->whereIn('id', $rows->pluck('item_id'))
            ->get()
            ->keyBy('id');

        $homeGuard = app(SkuHomeBinGuard::class);
        $needle = $search !== null && $search !== '' ? mb_strtolower($search) : null;

        $result = [];
        foreach ($rows as $row) {
            $variant = $variants->get($row->item_id);
            if (! $variant) {
                continue;
            }

            if ($homeGuard->currentHomeBinId($locationId, $row->item_id) !== null) {
                continue;
            }

            $sku = (string) ($variant->sku ?? '');
            $name = (string) ($variant->product?->name ?? '');

            if ($needle !== null
                && ! str_contains(mb_strtolower($sku), $needle)
                && ! str_contains(mb_strtolower($name), $needle)) {
                continue;
            }

            $result[] = [
                'variant_id' => $row->item_id,
                'sku' => $sku,
                'name' => $name,
                'pending_qty' => (int) $row->pending_qty,
                'thumbnail' => $this->resolveVariantThumbnail($variant),
            ];

            if (count($result) >= $limit) {
                break;
            }
        }

        return $result;
    }

    protected function resolveVariantThumbnail(ProductVariant $variant): ?string
    {
        if ($variant->relationLoaded('media') && $variant->media->isNotEmpty()) {
            $primary = $variant->media->firstWhere('is_primary', true);

            return $primary ? $primary->url : $variant->media->first()->url;
        }

        $product = $variant->relationLoaded('product') ? $variant->product : null;
        if ($product && $product->relationLoaded('media') && $product->media->isNotEmpty()) {
            $primary = $product->media->firstWhere('is_primary', true);

            return $primary ? $primary->url : $product->media->first()->url;
        }

        return null;
    }

    public function assignSkuToBin(string $locationId, string $binId, string $itemId, string $userId): array
    {
        $location = $this->locationRepository->find($locationId);
        if (! $location) {
            throw new ModelNotFoundException('Lokasi tidak ditemukan.');
        }
        if (! $location->enforcesStrictBinSku()) {
            throw new \DomainException('Penempatan SKU langsung hanya berlaku untuk Gudang Kecil.');
        }

        $bin = LocationBin::where('location_id', $locationId)->find($binId);
        if (! $bin) {
            throw new ModelNotFoundException('Rak tidak ditemukan.');
        }
        if ($bin->is_inbound) {
            throw new \DomainException('Rak inbound tidak dapat diisi SKU secara manual.');
        }

        if (! app(BinMultiSkuRuleService::class)->allowsMultiSku($bin)) {
            $occupant = app(BinOccupancyGuard::class)->currentOccupantItemId($binId);
            if ($occupant !== null && $occupant !== $itemId) {
                throw new \DomainException('Rak sudah berisi SKU lain.');
            }
        }

        app(SkuHomeBinGuard::class)->assertSkuFitsBin($locationId, $itemId, $binId);
        app(BinOccupancyGuard::class)->assertBinFitsSku($binId, $itemId);

        return DB::transaction(function () use ($locationId, $binId, $itemId, $userId) {
            SkuRackAssignment::updateOrCreate(
                [
                    'location_id' => $locationId,
                    'item_id' => $itemId,
                ],
                [
                    'bin_id' => $binId,
                    'assigned_by' => $userId,
                ]
            );

            return [
                'bin_id' => $binId,
                'item_id' => $itemId,
                'placed_qty' => 0,
            ];
        });
    }

    public function moveSkuToBin(string $locationId, string $sourceBinId, string $itemId, string $destinationBinId, string $userId): array
    {
        $location = $this->locationRepository->find($locationId);
        if (! $location) {
            throw new ModelNotFoundException('Lokasi tidak ditemukan.');
        }
        if (! $location->enforcesStrictBinSku()) {
            throw new \DomainException('Pindah rak hanya berlaku untuk Gudang Kecil.');
        }

        if ($sourceBinId === $destinationBinId) {
            throw new \DomainException('Rak tujuan harus berbeda dari rak asal.');
        }

        $sourceBin = LocationBin::where('location_id', $locationId)->find($sourceBinId);
        if (! $sourceBin) {
            throw new ModelNotFoundException('Rak asal tidak ditemukan.');
        }
        if ($sourceBin->is_inbound) {
            throw new \DomainException('Stok di rak inbound (DEFAULT) ditempatkan lewat Isi Rak, bukan dipindah dari sini.');
        }
        $destinationBin = LocationBin::where('location_id', $locationId)->find($destinationBinId);
        if (! $destinationBin) {
            throw new ModelNotFoundException('Rak tujuan tidak ditemukan.');
        }
        if ($destinationBin->is_inbound) {
            throw new \DomainException('Rak tujuan tidak boleh rak inbound.');
        }

        app(BinOccupancyGuard::class)->assertBinFitsSku($destinationBinId, $itemId);

        $inventoryService = app(InventoryService::class);

        return $this->withStockLock($itemId, $locationId, function () use ($locationId, $sourceBinId, $destinationBinId, $itemId, $userId, $inventoryService) {
            return DB::transaction(function () use ($locationId, $sourceBinId, $destinationBinId, $itemId, $userId, $inventoryService) {
                $transactionNumber = 'BIN-MOVE-'.Str::upper(Str::random(12));
                $rows = Inventory::query()
                    ->where('location_id', $locationId)
                    ->where('bin_id', $sourceBinId)
                    ->where('item_id', $itemId)
                    ->where('on_hand', '>', 0)
                    ->lockForUpdate()
                    ->get();

                if ($rows->isEmpty()) {
                    throw new \DomainException('Tidak ada stok yang bisa dipindah dari rak asal.');
                }

                $moved = 0;
                foreach ($rows as $row) {
                    $qty = (int) $row->on_hand;
                    if ($qty <= 0) {
                        continue;
                    }

                    $inventoryService->putaway([
                        'item_id' => $itemId,
                        'location_id' => $locationId,
                        'source_bin_id' => $sourceBinId,
                        'destination_bin_id' => $destinationBinId,
                        'qty' => $qty,
                        'batch_no' => $row->batch_no ?? '',
                        'serial_no' => $row->serial_no ?? '',
                        'created_by' => "user:{$userId}",
                        'transaction_number' => $transactionNumber,
                        'source_out' => 'BIN_TRANSFER_OUT',
                        'source_in' => 'BIN_TRANSFER_IN',
                        'skip_home_bin_guard' => true,
                    ]);

                    $moved += $qty;
                }

                return [
                    'source_bin_id' => $sourceBinId,
                    'destination_bin_id' => $destinationBinId,
                    'item_id' => $itemId,
                    'moved_qty' => $moved,
                ];
            });
        });
    }

    public function removeSkuFromBin(string $locationId, string $binId, string $itemId, string $createdBy): array
    {
        $location = $this->locationRepository->find($locationId);
        if (! $location) {
            throw new ModelNotFoundException('Lokasi tidak ditemukan.');
        }
        if (! $location->enforcesStrictBinSku()) {
            throw new \DomainException('Keluarkan SKU hanya berlaku untuk Gudang Kecil.');
        }

        $bin = LocationBin::where('location_id', $locationId)->find($binId);
        if (! $bin) {
            throw new ModelNotFoundException('Rak tidak ditemukan.');
        }
        if ($bin->is_inbound) {
            throw new \DomainException('Rak inbound tidak dapat dikosongkan.');
        }

        $onHand = (int) Inventory::query()
            ->where('location_id', $locationId)
            ->where('bin_id', $binId)
            ->where('item_id', $itemId)
            ->sum('on_hand');

        if ($onHand <= 0) {
            SkuRackAssignment::where('bin_id', $binId)
                ->where('item_id', $itemId)
                ->delete();

            return [
                'bin_id' => $binId,
                'item_id' => $itemId,
                'removed_qty' => 0,
                'adjustment_no' => null,
            ];
        }

        $adjustment = app(StockAdjustmentService::class)->create([
            'transaction_date' => now()->toDateTimeString(),
            'location_id' => $locationId,
            'notes' => "Keluarkan SKU dari rak {$bin->bin_final_code}",
            'created_by' => $createdBy,
            'items' => [[
                'item_id' => $itemId,
                'bin_id' => $binId,
                'actual_qty' => 0,
                'notes' => "Dikeluarkan dari rak {$bin->bin_final_code}",
            ]],
        ]);

        try {
            (new ProcessStockAdjustmentJob($adjustment->id, $createdBy))->handle(
                app(InventoryRepository::class),
                app(InventoryMovementRepository::class)
            );
        } catch (\Throwable $e) {
            Log::warning('Gagal eksekusi sinkron ProcessStockAdjustmentJob: '.$e->getMessage());
        }

        SkuRackAssignment::where('bin_id', $binId)
            ->where('item_id', $itemId)
            ->delete();

        return [
            'bin_id' => $binId,
            'item_id' => $itemId,
            'removed_qty' => $onHand,
            'adjustment_no' => $adjustment->adjustment_no,
        ];
    }
}
