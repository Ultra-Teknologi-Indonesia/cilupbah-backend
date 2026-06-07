<?php

namespace Modules\Inventory\Services;

use Modules\Inventory\Repositories\ReservedStockRepository;
use Modules\Inventory\Repositories\InventoryRepository;
use Modules\Inventory\Repositories\InventoryMovementRepository;
use Modules\Inventory\Models\ReservedStock;
use Modules\Inventory\Jobs\ProcessReservedStockJob;
use Illuminate\Support\Facades\DB;

class ReservedStockService
{
    public function __construct(
        protected ReservedStockRepository $reservedStockRepository,
        protected InventoryRepository $inventoryRepository,
        protected InventoryMovementRepository $movementRepository,
    ) {}

    public function getAllPaginated(int $limit = 10)
    {
        return $this->reservedStockRepository->getAllPaginated($limit);
    }

    public function getById(string $id): ?ReservedStock
    {
        return $this->reservedStockRepository->findById($id);
    }

    public function create(array $data): ReservedStock
    {
        return DB::transaction(function () use ($data) {
            $reservedStockNo = $this->reservedStockRepository->generateReservedStockNo();

            $reservedStock = $this->reservedStockRepository->create([
                'reserved_stock_no' => $reservedStockNo,
                'location_id' => $data['location_id'],
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
                'status' => ReservedStock::STATUS_ACTIVE,
                'is_active' => $data['is_active'] ?? true,
                'notes' => $data['notes'] ?? null,
                'created_by' => $data['created_by'],
            ]);

            foreach ($data['items'] as $itemData) {
                $this->reservedStockRepository->createItem([
                    'reserved_stock_id' => $reservedStock->id,
                    'item_id' => $itemData['item_id'],
                    'bin_id' => $itemData['bin_id'] ?? null,
                    'qty' => $itemData['qty'],
                ]);
            }

            ProcessReservedStockJob::dispatch($reservedStock->id);

            return $this->reservedStockRepository->findById($reservedStock->id);
        });
    }

    public function cancel(string $id): ReservedStock
    {
        return DB::transaction(function () use ($id) {
            $reservedStock = $this->reservedStockRepository->findByIdForUpdate($id);

            if (!$reservedStock) {
                throw new \Exception('Dokumen reserved stock tidak ditemukan.');
            }

            if ($reservedStock->status !== ReservedStock::STATUS_ACTIVE) {
                throw new \Exception("Hanya dokumen ACTIVE yang bisa di-cancel (status saat ini: {$reservedStock->status}).");
            }

            $reservedStock->load('items');

            foreach ($reservedStock->items as $item) {
                $inventory = $this->inventoryRepository->findExactForUpdate(
                    $item->item_id,
                    $reservedStock->location_id,
                    $item->bin_id,
                );

                if ($inventory) {
                    $inventory->reserved -= $item->qty;
                    if ($inventory->reserved < 0) {
                        $inventory->reserved = 0;
                    }
                    $this->inventoryRepository->updateStock($inventory);

                    $this->movementRepository->create([
                        'item_id' => $item->item_id,
                        'location_id' => $reservedStock->location_id,
                        'bin_id' => $item->bin_id,
                        'transaction_number' => $reservedStock->reserved_stock_no,
                        'source' => 'RESERVE_CANCEL',
                        'qty' => $item->qty,
                        'balance' => $inventory->on_hand,
                        'transaction_date' => now(),
                        'created_by' => 'system',
                    ]);
                }
            }

            $reservedStock->update([
                'status' => ReservedStock::STATUS_CANCELLED,
                'is_active' => false,
            ]);

            return $this->reservedStockRepository->findById($id);
        });
    }

    public function releaseExpired(): int
    {
        $expired = $this->reservedStockRepository->getExpired();
        $count = 0;

        foreach ($expired as $reservedStock) {
            DB::transaction(function () use ($reservedStock) {
                foreach ($reservedStock->items as $item) {
                    $inventory = $this->inventoryRepository->findExactForUpdate(
                        $item->item_id,
                        $reservedStock->location_id,
                        $item->bin_id,
                    );

                    if ($inventory) {
                        $inventory->reserved -= $item->qty;
                        if ($inventory->reserved < 0) {
                            $inventory->reserved = 0;
                        }
                        $this->inventoryRepository->updateStock($inventory);

                        $this->movementRepository->create([
                            'item_id' => $item->item_id,
                            'location_id' => $reservedStock->location_id,
                            'bin_id' => $item->bin_id,
                            'transaction_number' => $reservedStock->reserved_stock_no,
                            'source' => 'RESERVE_EXPIRED',
                            'qty' => $item->qty,
                            'balance' => $inventory->on_hand,
                            'transaction_date' => now(),
                            'created_by' => 'system',
                        ]);
                    }
                }

                $this->reservedStockRepository->deactivate($reservedStock->id);
            });

            $count++;
        }

        return $count;
    }
}
