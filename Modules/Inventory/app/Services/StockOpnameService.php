<?php

namespace Modules\Inventory\Services;

use Modules\Inventory\Repositories\StockOpnameRepository;
use Modules\Inventory\Repositories\InventoryRepository;
use Modules\Inventory\Models\StockOpname;
use Modules\Inventory\Jobs\ProcessStockOpnameFinalizeJob;
use Modules\Warehouse\Models\LocationBin;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

class StockOpnameService
{
    public function __construct(
        protected StockOpnameRepository $opnameRepository,
        protected InventoryRepository $inventoryRepository,
    ) {}

    public function getAllPaginated(int $limit = 10)
    {
        return $this->opnameRepository->getAllPaginated($limit);
    }

    public function getById(string $id): ?StockOpname
    {
        return $this->opnameRepository->findById($id);
    }

    public function create(array $data): StockOpname
    {
        return DB::transaction(function () use ($data) {
            $opnameNo = $this->opnameRepository->generateOpnameNo();

            $opname = $this->opnameRepository->create([
                'opname_no' => $opnameNo,
                'location_id' => $data['location_id'],
                'filter_zone_id' => $data['filter_zone_id'] ?? null,
                'filter_floor_codes' => $data['filter_floor_codes'] ?? null,
                'filter_row_codes' => $data['filter_row_codes'] ?? null,
                'filter_column_codes' => $data['filter_column_codes'] ?? null,
                'status' => StockOpname::STATUS_DRAFT,
                'notes' => $data['notes'] ?? null,
                'created_by' => $data['created_by'],
                'process_by' => $data['process_by'] ?? null,
            ]);

            $bins = $this->resolveBins($data);

            $now = now();
            $items = [];

            foreach ($bins as $bin) {
                $inventories = \Modules\Inventory\Models\Inventory::where('location_id', $data['location_id'])
                    ->where('bin_id', $bin->id)
                    ->where('on_hand', '>', 0)
                    ->get();

                foreach ($inventories as $inventory) {
                    $items[] = [
                        'id' => Uuid::uuid7()->getHex()->toString(),
                        'stock_opname_id' => $opname->id,
                        'item_id' => $inventory->item_id,
                        'bin_id' => $inventory->bin_id,
                        'batch_no' => $inventory->batch_no ?: null,
                        'serial_no' => $inventory->serial_no ?: null,
                        'expired_date' => $inventory->expired_date,
                        'qty_system' => $inventory->on_hand,
                        'qty_actual' => null,
                        'qty_difference' => null,
                        'reason' => null,
                        'counted_by' => null,
                        'counted_at' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            if (!empty($items)) {
                foreach (array_chunk($items, 500) as $chunk) {
                    $this->opnameRepository->createItems($chunk);
                }
            }

            return $this->opnameRepository->findById($opname->id);
        });
    }

    public function startProcess(string $id, string $processBy): StockOpname
    {
        $opname = $this->opnameRepository->findById($id);

        if (!$opname) {
            throw new \Exception('Dokumen stock opname tidak ditemukan.');
        }

        if ($opname->status !== StockOpname::STATUS_DRAFT) {
            throw new \Exception("Hanya dokumen DRAFT yang bisa diproses (status saat ini: {$opname->status}).");
        }

        $this->opnameRepository->updateStatus($id, StockOpname::STATUS_IN_PROGRESS, [
            'process_by' => $processBy,
        ]);

        return $this->opnameRepository->findById($id);
    }

    public function countItem(string $opnameId, string $itemId, array $data): StockOpname
    {
        $opname = $this->opnameRepository->findById($opnameId);

        if (!$opname) {
            throw new \Exception('Dokumen stock opname tidak ditemukan.');
        }

        if (!in_array($opname->status, [StockOpname::STATUS_DRAFT, StockOpname::STATUS_IN_PROGRESS])) {
            throw new \Exception("Hanya dokumen DRAFT/IN_PROGRESS yang bisa di-count (status saat ini: {$opname->status}).");
        }

        $qtyActual = $data['qty_actual'];
        $item = $opname->items->firstWhere('id', $itemId);

        if (!$item) {
            throw new \Exception('Item opname tidak ditemukan.');
        }

        $this->opnameRepository->updateItem($itemId, [
            'qty_actual' => $qtyActual,
            'qty_difference' => $qtyActual - $item->qty_system,
            'reason' => $data['reason'] ?? null,
            'counted_by' => $data['counted_by'],
            'counted_at' => now(),
        ]);

        return $this->opnameRepository->findById($opnameId);
    }

    public function finalize(string $id, string $finalizedBy): StockOpname
    {
        $opname = $this->opnameRepository->findById($id);

        if (!$opname) {
            throw new \Exception('Dokumen stock opname tidak ditemukan.');
        }

        if (!in_array($opname->status, [StockOpname::STATUS_DRAFT, StockOpname::STATUS_IN_PROGRESS])) {
            throw new \Exception("Hanya dokumen DRAFT/IN_PROGRESS yang bisa di-finalize (status saat ini: {$opname->status}).");
        }

        $uncounted = $opname->items->whereNull('qty_actual')->count();
        if ($uncounted > 0) {
            throw new \Exception("Masih ada {$uncounted} item yang belum dihitung.");
        }

        $this->opnameRepository->updateStatus($id, StockOpname::STATUS_FINALIZED, [
            'finalized_by' => $finalizedBy,
            'finalized_at' => now(),
        ]);

        ProcessStockOpnameFinalizeJob::dispatch($id, $finalizedBy);

        return $this->opnameRepository->findById($id);
    }

    public function cancel(string $id): StockOpname
    {
        $opname = $this->opnameRepository->findById($id);

        if (!$opname) {
            throw new \Exception('Dokumen stock opname tidak ditemukan.');
        }

        if ($opname->status === StockOpname::STATUS_FINALIZED) {
            throw new \Exception('Dokumen yang sudah di-finalize tidak bisa di-cancel.');
        }

        $this->opnameRepository->updateStatus($id, StockOpname::STATUS_CANCELLED);

        return $this->opnameRepository->findById($id);
    }

    public function delete(string $id): bool
    {
        $opname = $this->opnameRepository->findById($id);

        if (!$opname) {
            throw new \Exception('Dokumen stock opname tidak ditemukan.');
        }

        if ($opname->status !== StockOpname::STATUS_DRAFT) {
            throw new \Exception("Hanya dokumen DRAFT yang bisa dihapus (status saat ini: {$opname->status}).");
        }

        return $this->opnameRepository->delete($id);
    }

    public function markPrinted(string $id, string $printedBy): StockOpname
    {
        $opname = $this->opnameRepository->findById($id);

        if (!$opname) {
            throw new \Exception('Dokumen stock opname tidak ditemukan.');
        }

        $this->opnameRepository->updateStatus($id, $opname->status, [
            'printed_by' => $printedBy,
            'printed_at' => now(),
        ]);

        return $this->opnameRepository->findById($id);
    }

    public function getItemsPaginated(string $opnameId, int $limit = 10)
    {
        return $this->opnameRepository->getItemsPaginated($opnameId, $limit);
    }

    public function getBins(string $locationId, ?string $zoneId = null)
    {
        return $this->opnameRepository->getBinsByLocation($locationId, $zoneId);
    }

    public function getFloors(string $locationId, ?string $zoneId = null)
    {
        return $this->opnameRepository->getFloorsByLocation($locationId, $zoneId);
    }

    public function getRows(string $locationId, string $floorCode, ?string $zoneId = null)
    {
        return $this->opnameRepository->getRowsByFloor($locationId, $floorCode, $zoneId);
    }

    public function getColumns(string $locationId, string $floorCode, string $rowCode, ?string $zoneId = null)
    {
        return $this->opnameRepository->getColumnsByRow($locationId, $floorCode, $rowCode, $zoneId);
    }

    private function resolveBins(array $data): \Illuminate\Database\Eloquent\Collection|\Illuminate\Support\Collection
    {
        $query = LocationBin::where('location_id', $data['location_id']);

        if (!empty($data['filter_zone_id'])) {
            $query->where('zone_id', $data['filter_zone_id']);
        }

        if (!empty($data['filter_floor_codes'])) {
            $query->whereIn('floor_code', $data['filter_floor_codes']);
        }

        if (!empty($data['filter_row_codes'])) {
            $query->whereIn('row_code', $data['filter_row_codes']);
        }

        if (!empty($data['filter_column_codes'])) {
            $query->whereIn('column_code', $data['filter_column_codes']);
        }

        return $query->get();
    }
}
