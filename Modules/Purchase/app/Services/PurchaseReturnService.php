<?php

namespace Modules\Purchase\Services;

use Modules\Purchase\Repositories\PurchaseReturnRepository;
use Modules\Purchase\Models\PurchaseReturn;
use Modules\Inventory\Repositories\InventoryRepository;
use Modules\Inventory\Repositories\InventoryMovementRepository;
use Illuminate\Support\Facades\DB;

class PurchaseReturnService
{
    public function __construct(
        protected PurchaseReturnRepository $returnRepository,
        protected InventoryRepository $inventoryRepository,
        protected InventoryMovementRepository $movementRepository,
    ) {}

    public function getAllPaginated(int $limit = 10)
    {
        return $this->returnRepository->getAllPaginated($limit);
    }

    public function getById(string $id): ?PurchaseReturn
    {
        return $this->returnRepository->findById($id);
    }

    public function create(array $data): PurchaseReturn
    {
        return DB::transaction(function () use ($data) {
            $data['return_number'] = $data['return_number'] ?? $this->returnRepository->generateReturnNo();
            $data['status'] = $data['status'] ?? PurchaseReturn::STATUS_DRAFT;

            $totalAmount = 0;
            foreach (($data['items'] ?? []) as $item) {
                $totalAmount += $item['qty'] * ($item['unit_price'] ?? 0);
            }
            $data['total_amount'] = $totalAmount;

            $return = $this->returnRepository->create($data);

            foreach (($data['items'] ?? []) as $itemData) {
                $itemData['purchase_return_id'] = $return->id;
                $itemData['subtotal'] = $itemData['qty'] * ($itemData['unit_price'] ?? 0);
                $this->returnRepository->createItem($itemData);
            }

            return $return->load('items.product:id,name,sku');
        });
    }

    public function delete(string $id): bool
    {
        $return = $this->returnRepository->findById($id);
        if (! $return) {
            throw new \Exception('Purchase return tidak ditemukan.');
        }
        if ($return->status !== PurchaseReturn::STATUS_DRAFT) {
            throw new \Exception('Hanya return berstatus DRAFT yang bisa dihapus.');
        }
        return $this->returnRepository->delete($return);
    }

    public function process(string $id, string $processedBy): PurchaseReturn
    {
        return DB::transaction(function () use ($id, $processedBy) {
            $return = $this->returnRepository->findById($id);

            if (! $return) {
                throw new \Exception('Purchase return tidak ditemukan.');
            }

            if ($return->status !== PurchaseReturn::STATUS_DRAFT) {
                throw new \Exception('Hanya return berstatus DRAFT yang bisa diproses.');
            }

            foreach ($return->items as $item) {
                $sourceInventory = $this->inventoryRepository->findExactForUpdate(
                    $item->item_id,
                    $return->location_id,
                    null,
                    '',
                    '',
                );

                if (! $sourceInventory || $sourceInventory->on_hand < $item->qty) {
                    $available = $sourceInventory ? $sourceInventory->on_hand : 0;
                    throw new \Exception("Stok tidak cukup untuk item {$item->item_id}. Tersedia: {$available}, dibutuhkan: {$item->qty}.");
                }

                $sourceInventory->on_hand -= $item->qty;
                $this->inventoryRepository->updateStock($sourceInventory);

                $this->movementRepository->create([
                    'item_id'            => $item->item_id,
                    'location_id'        => $return->location_id,
                    'bin_id'             => null,
                    'transaction_number' => $return->return_number,
                    'source'             => 'PURCHASE_RETURN_OUT',
                    'qty'                => -$item->qty,
                    'balance'            => $sourceInventory->on_hand,
                    'transaction_date'   => now(),
                    'created_by'         => $processedBy,
                ]);
            }

            $return->update([
                'status'       => PurchaseReturn::STATUS_COMPLETED,
                'processed_by' => $processedBy,
                'processed_at' => now(),
            ]);

            return $this->returnRepository->findById($id);
        });
    }

    public function getUnpaid(int $limit = 10)
    {
        return $this->returnRepository->getUnpaid($limit);
    }
}
