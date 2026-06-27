<?php

namespace Modules\Purchase\Services;

use Modules\Purchase\Repositories\PurchaseOrderRepository;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Inbound\Services\InboundService;
use Modules\Inventory\Repositories\InventoryRepository;
use App\Traits\StockLockable;
use Illuminate\Support\Facades\DB;

class PurchaseOrderService
{
    use StockLockable;

    public function __construct(
        protected PurchaseOrderRepository $poRepository,
        protected InboundService $inboundService,
        protected InventoryRepository $inventoryRepository,
    ) {}

    public function getAllPaginated(int $limit = 10)
    {
        return $this->poRepository->getAllPaginated($limit);
    }

    public function getReceivable(int $limit = 10)
    {
        return $this->poRepository->getReceivable($limit);
    }

    public function getById(string $id): ?PurchaseOrder
    {
        return $this->poRepository->findById($id);
    }

    public function create(array $data): PurchaseOrder
    {
        return DB::transaction(function () use ($data) {
            $data['po_number'] = $data['po_number'] ?? $this->poRepository->generatePoNumber();
            $data['status'] = PurchaseOrder::STATUS_DRAFT;
            $data['created_by'] = auth()->user()?->name ?? 'system';

            $totals = $this->calculateTotals($data['items'], $data['is_tax_included'] ?? false);
            $data = array_merge($data, $totals);

            $po = $this->poRepository->create($data);

            foreach ($data['items'] as $itemData) {
                $itemData['purchase_order_id'] = $po->id;
                $this->calculateItemAmounts($itemData);
                $this->poRepository->createItem($itemData);
            }

            return $po->load('items.variant.product:id,name');
        });
    }

    public function update(string $id, array $data): PurchaseOrder
    {
        return DB::transaction(function () use ($id, $data) {
            $po = $this->poRepository->findById($id);

            if (!$po) {
                throw new \Exception('PO tidak ditemukan.');
            }

            if ($po->status !== PurchaseOrder::STATUS_DRAFT) {
                throw new \Exception('Hanya PO berstatus DRAFT yang bisa diedit.');
            }

            $totals = $this->calculateTotals($data['items'] ?? [], $data['is_tax_included'] ?? false);
            $data = array_merge($data, $totals);

            $po->update($data);

            if (isset($data['items'])) {
                $po->items()->delete();
                foreach ($data['items'] as $itemData) {
                    $itemData['purchase_order_id'] = $po->id;
                    $this->calculateItemAmounts($itemData);
                    $this->poRepository->createItem($itemData);
                }
            }

            return $this->getById($id);
        });
    }

    public function approve(string $id): PurchaseOrder
    {
        return DB::transaction(function () use ($id) {
            $po = $this->poRepository->findByIdForUpdate($id);

            if (!$po) {
                throw new \Exception('PO tidak ditemukan.');
            }

            if ($po->status !== PurchaseOrder::STATUS_DRAFT) {
                throw new \Exception("PO sudah berstatus {$po->status}, tidak bisa diapprove.");
            }

            $this->poRepository->updateStatus($po, PurchaseOrder::STATUS_OPEN);

            $po->load('items');
            foreach ($po->items as $item) {
                $this->adjustOnOrder($item->item_id, $po->location_id, $item->qty);
            }

            return $this->getById($id);
        });
    }

    public function receive(string $poId, array $data): array
    {
        return DB::transaction(function () use ($poId, $data) {
            $po = $this->poRepository->findByIdForUpdate($poId);

            if (!$po) {
                throw new \Exception('PO tidak ditemukan.');
            }

            if (!$po->isReceivable()) {
                throw new \Exception("PO berstatus {$po->status}, tidak bisa di-receive.");
            }

            $poItemsDict = $po->items->keyBy('id');
            $inboundItems = [];

            foreach ($data['items'] as $receiveItem) {
                $poItem = $poItemsDict->get($receiveItem['purchase_order_item_id']);
                if (!$poItem) {
                    throw new \Exception("PO Item ID {$receiveItem['purchase_order_item_id']} tidak ditemukan.");
                }

                $pending = $poItem->pendingQty();
                if ($receiveItem['qty'] > $pending) {
                    throw new \Exception("Qty receive ({$receiveItem['qty']}) melebihi pending ({$pending}) untuk item {$poItem->item_id}.");
                }

                $this->poRepository->updateItemReceivedQty($poItem->id, $receiveItem['qty']);
                $this->adjustOnOrder($poItem->item_id, $po->location_id, -$receiveItem['qty']);

                $inboundItems[] = [
                    'item_id'      => $poItem->item_id,
                    'expected_qty' => $receiveItem['qty'],
                ];
            }

            $allReceived = $po->items->fresh()->every(fn ($item) => $item->isFullyReceived());
            $newStatus = $allReceived
                ? PurchaseOrder::STATUS_FULLY_RECEIVED
                : PurchaseOrder::STATUS_PARTIAL_RECEIVED;
            $this->poRepository->updateStatus($po, $newStatus);

            $inbound = $this->inboundService->receiveFromPO([
                'location_id'      => $po->location_id,
                'reference_number' => $po->po_number,
                'source_id'        => $po->id,
                'expected_date'    => now()->toDateString(),
                'created_by'       => $data['received_by'],
                'items'            => $inboundItems,
            ]);

            return [
                'purchase_order' => $this->getById($poId),
                'inbound'        => $inbound,
            ];
        });
    }

    public function cancel(string $id): PurchaseOrder
    {
        return DB::transaction(function () use ($id) {
            $po = $this->poRepository->findByIdForUpdate($id);

            if (!$po) {
                throw new \Exception('PO tidak ditemukan.');
            }

            if ($po->status === PurchaseOrder::STATUS_FULLY_RECEIVED) {
                throw new \Exception('PO yang sudah fully received tidak bisa dibatalkan.');
            }

            if ($po->status === PurchaseOrder::STATUS_CANCELLED) {
                throw new \Exception('PO sudah dibatalkan.');
            }

            if (in_array($po->status, [PurchaseOrder::STATUS_OPEN, PurchaseOrder::STATUS_PARTIAL_RECEIVED])) {
                $po->load('items');
                foreach ($po->items as $item) {
                    $pendingQty = $item->pendingQty();
                    if ($pendingQty > 0) {
                        $this->adjustOnOrder($item->item_id, $po->location_id, -$pendingQty);
                    }
                }
            }

            $this->poRepository->updateStatus($po, PurchaseOrder::STATUS_CANCELLED);

            return $this->getById($id);
        });
    }

    public function delete(string $id): bool
    {
        $po = $this->poRepository->findById($id);

        if (!$po) {
            throw new \Exception('PO tidak ditemukan.');
        }

        if ($po->status !== PurchaseOrder::STATUS_DRAFT) {
            throw new \Exception('Hanya PO berstatus DRAFT yang bisa dihapus.');
        }

        return $this->poRepository->delete($po);
    }

    private function calculateItemAmounts(array &$item): void
    {
        $price = $item['unit_price'] ?? 0;
        $qty = $item['qty'] ?? 0;
        $discPercent = $item['disc'] ?? 0;

        $lineTotal = $price * $qty;
        $item['disc_amount'] = round($lineTotal * $discPercent / 100, 2);
        $item['tax_amount'] = $item['tax_amount'] ?? 0;
        $item['amount'] = round($lineTotal - $item['disc_amount'] + $item['tax_amount'], 2);
    }

    private function calculateTotals(array $items, bool $isTaxIncluded): array
    {
        $subTotal = 0;
        $totalDisc = 0;
        $totalTax = 0;

        foreach ($items as $item) {
            $price = $item['unit_price'] ?? 0;
            $qty = $item['qty'] ?? 0;
            $discPercent = $item['disc'] ?? 0;

            $lineTotal = $price * $qty;
            $discAmount = round($lineTotal * $discPercent / 100, 2);
            $taxAmount = $item['tax_amount'] ?? 0;

            $subTotal += $lineTotal;
            $totalDisc += $discAmount;
            $totalTax += $taxAmount;
        }

        return [
            'sub_total'    => round($subTotal, 2),
            'total_disc'   => round($totalDisc, 2),
            'total_tax'    => round($totalTax, 2),
            'total_amount' => round($subTotal - $totalDisc + $totalTax, 2),
        ];
    }

    private function adjustOnOrder(string $itemId, string $locationId, int $qty): void
    {
        $this->withStockLock($itemId, $locationId, function () use ($itemId, $locationId, $qty) {
            DB::transaction(function () use ($itemId, $locationId, $qty) {
                $inventory = $this->inventoryRepository->findOrCreateForUpdate($itemId, $locationId, null);

                if ($qty <= 0 && $inventory->on_order === 0) {
                    return;
                }

                $inventory->on_order = max(0, $inventory->on_order + $qty);
                $this->inventoryRepository->updateStock($inventory);
            });
        });
    }
}
