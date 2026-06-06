<?php

namespace Modules\Purchase\Services;

use Modules\Purchase\Repositories\PurchaseOrderRepository;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Inbound\Services\InboundService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PurchaseOrderService
{
    public function __construct(
        protected PurchaseOrderRepository $poRepository,
        protected InboundService $inboundService
    ) {}

    public function getAllPaginated(int $limit = 10)
    {
        return $this->poRepository->getAllPaginated($limit);
    }

    public function getReceivable(int $limit = 10)
    {
        return $this->poRepository->getReceivable($limit);
    }

    public function getById(int $id): ?PurchaseOrder
    {
        return $this->poRepository->findById($id);
    }

    public function create(array $data): PurchaseOrder
    {
        return DB::transaction(function () use ($data) {
            $data['po_number'] = $data['po_number'] ?? 'PO-' . now()->format('Ymd') . '-' . Str::upper(Str::random(4));
            $data['status'] = PurchaseOrder::STATUS_DRAFT;

            $totalAmount = 0;
            foreach ($data['items'] as $item) {
                $subtotal = $item['qty'] * ($item['unit_price'] ?? 0);
                $totalAmount += $subtotal;
            }
            $data['total_amount'] = $totalAmount;

            $po = $this->poRepository->create($data);

            foreach ($data['items'] as $itemData) {
                $itemData['purchase_order_id'] = $po->id;
                $itemData['subtotal'] = $itemData['qty'] * ($itemData['unit_price'] ?? 0);
                $this->poRepository->createItem($itemData);
            }

            return $po->load('items.product:id,name,sku');
        });
    }

    public function approve(int $id): PurchaseOrder
    {
        return DB::transaction(function () use ($id) {
            $po = $this->poRepository->findByIdForUpdate($id);

            if (! $po) {
                throw new \Exception('PO tidak ditemukan.');
            }

            if ($po->status !== PurchaseOrder::STATUS_DRAFT) {
                throw new \Exception("PO sudah berstatus {$po->status}, tidak bisa diapprove.");
            }

            $this->poRepository->updateStatus($po, PurchaseOrder::STATUS_OPEN);

            return $this->getById($id);
        });
    }

    /** Receive items from PO → creates Inbound GRN + links back to PO */
    public function receive(int $poId, array $data): array
    {
        return DB::transaction(function () use ($poId, $data) {
            $po = $this->poRepository->findByIdForUpdate($poId);

            if (! $po) {
                throw new \Exception('PO tidak ditemukan.');
            }

            if (! $po->isReceivable()) {
                throw new \Exception("PO berstatus {$po->status}, tidak bisa di-receive.");
            }

            $poItemsDict = $po->items->keyBy('id');
            $inboundItems = [];

            foreach ($data['items'] as $receiveItem) {
                $poItem = $poItemsDict->get($receiveItem['purchase_order_item_id']);
                if (! $poItem) {
                    throw new \Exception("PO Item ID {$receiveItem['purchase_order_item_id']} tidak ditemukan.");
                }

                $pending = $poItem->pendingQty();
                if ($receiveItem['qty'] > $pending) {
                    throw new \Exception("Qty receive ({$receiveItem['qty']}) melebihi pending ({$pending}) untuk item {$poItem->item_id}.");
                }

                $this->poRepository->updateItemReceivedQty($poItem->id, $receiveItem['qty']);

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

    public function update(int $id, array $data): PurchaseOrder
    {
        $po = $this->poRepository->findById($id);

        if (! $po) {
            throw new \Exception('PO tidak ditemukan.');
        }

        if ($po->status !== PurchaseOrder::STATUS_DRAFT) {
            throw new \Exception("Hanya PO berstatus DRAFT yang bisa diedit.");
        }

        return $this->poRepository->update($po, $data);
    }

    public function cancel(int $id): PurchaseOrder
    {
        return DB::transaction(function () use ($id) {
            $po = $this->poRepository->findByIdForUpdate($id);

            if (! $po) {
                throw new \Exception('PO tidak ditemukan.');
            }

            if ($po->status === PurchaseOrder::STATUS_FULLY_RECEIVED) {
                throw new \Exception('PO yang sudah fully received tidak bisa dibatalkan.');
            }

            if ($po->status === PurchaseOrder::STATUS_CANCELLED) {
                throw new \Exception('PO sudah dibatalkan.');
            }

            $this->poRepository->updateStatus($po, PurchaseOrder::STATUS_CANCELLED);

            return $this->getById($id);
        });
    }

    public function delete(int $id): bool
    {
        $po = $this->poRepository->findById($id);

        if (! $po) {
            throw new \Exception('PO tidak ditemukan.');
        }

        if ($po->status !== PurchaseOrder::STATUS_DRAFT) {
            throw new \Exception('Hanya PO berstatus DRAFT yang bisa dihapus.');
        }

        return $this->poRepository->delete($po);
    }
}
