<?php

namespace Modules\Inbound\Services;

use Modules\Inbound\Repositories\InboundRepository;
use Modules\Inbound\Models\Inbound;
use Modules\Inventory\Services\InventoryService;
use Modules\Warehouse\Services\LocationBinService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InboundService
{
    public function __construct(
        protected InboundRepository $inboundRepository,
        protected InventoryService $inventoryService,
        protected LocationBinService $binService
    ) {}

    public function getAllPaginated(int $limit = 10)
    {
        return $this->inboundRepository->getAllPaginated($limit);
    }

    public function getById(int $id): ?Inbound
    {
        return $this->inboundRepository->findById($id);
    }

    public function createDraft(array $data): Inbound
    {
        return DB::transaction(function () use ($data) {
            $data['transaction_number'] = $data['transaction_number'] ?? 'INB-' . Str::upper(Str::random(8));
            $data['status'] = 'DRAFT';

            $inbound = $this->inboundRepository->create($data);

            foreach ($data['items'] as $itemData) {
                $itemData['inbound_id'] = $inbound->id;
                $this->inboundRepository->createItem($itemData);
            }

            return $inbound->load('items');
        });
    }

    public function receive(int $inboundId, array $data): Inbound
    {
        return DB::transaction(function () use ($inboundId, $data) {
            $inbound = $this->inboundRepository->findByIdForUpdate($inboundId);

            if (!$inbound) {
                throw new \Exception("Dokumen Inbound tidak ditemukan.");
            }

            if (in_array($inbound->status, ['COMPLETED', 'CANCELLED'])) {
                throw new \Exception("Inbound sudah berstatus {$inbound->status}.");
            }

            $defaultBin = $this->binService->getDefaultBin($inbound->location_id);
            if (!$defaultBin) {
                throw new \Exception("Gudang ini belum memiliki Bin Inbound default.");
            }

            $itemsDict = $inbound->items->keyBy('id');
            $allCompleted = true;

            foreach ($data['items'] as $receiptData) {
                $inboundItem = $itemsDict->get($receiptData['inbound_item_id']);
                if (!$inboundItem) {
                    throw new \Exception("Item ID {$receiptData['inbound_item_id']} tidak terkait dengan Inbound ini.");
                }

                if ($inboundItem->received_qty + $receiptData['qty'] > $inboundItem->expected_qty) {
                    throw new \Exception("Jumlah terima melebih ekspektasi untuk item {$inboundItem->item_id}.");
                }

                $this->inboundRepository->createReceipt([
                    'inbound_item_id' => $inboundItem->id,
                    'qty' => $receiptData['qty'],
                    'bin_id' => $defaultBin->id,
                    'batch_no' => $receiptData['batch_no'] ?? null,
                    'serial_no' => $receiptData['serial_no'] ?? null,
                    'received_by' => $data['received_by'],
                    'received_date' => now(),
                ]);

                $this->inboundRepository->updateItemReceivedQty($inboundItem->id, $receiptData['qty']);
                $inboundItem->received_qty += $receiptData['qty'];

                if ($inboundItem->received_qty < $inboundItem->expected_qty) {
                    $allCompleted = false;
                }

                $this->inventoryService->adjust([
                    'item_id' => $inboundItem->item_id,
                    'location_id' => $inbound->location_id,
                    'bin_id' => $defaultBin->id,
                    'batch_no' => $receiptData['batch_no'] ?? '',
                    'serial_no' => $receiptData['serial_no'] ?? '',
                    'qty' => $receiptData['qty'],
                    'transaction_number' => $inbound->transaction_number,
                    'created_by' => $data['received_by'],
                ]);
            }

            $newStatus = $allCompleted ? 'COMPLETED' : 'PARTIAL';
            $this->inboundRepository->updateStatus($inbound, $newStatus);

            return $this->getById($inboundId);
        });
    }

    public function getReceivedItemsPaginated(int $limit = 10)
    {
        return $this->inboundRepository->getReceivedItemsPaginated($limit);
    }

    public function autoPutaway(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $results = [];
            foreach ($data['putaway_items'] as $item) {
                $defaultBin = $this->binService->getDefaultBin($data['location_id']);
                if (!$defaultBin) {
                    throw new \Exception("Gudang ini belum memiliki Bin Inbound default.");
                }

                $inventory = $this->inventoryService->putaway([
                    'item_id' => $item['item_id'],
                    'location_id' => $data['location_id'],
                    'source_bin_id' => $defaultBin->id,
                    'destination_bin_id' => $item['destination_bin_id'],
                    'qty' => $item['qty'],
                    'batch_no' => $item['batch_no'] ?? '',
                    'serial_no' => $item['serial_no'] ?? '',
                    'created_by' => $data['created_by'],
                ]);

                $results[] = $inventory;
            }

            return $results;
        });
    }
}
