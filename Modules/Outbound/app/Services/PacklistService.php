<?php

namespace Modules\Outbound\Services;

use Modules\Outbound\Repositories\PacklistRepository;
use Modules\Outbound\Models\Packlist;
use Modules\Outbound\Jobs\ProcessPacklistCompleteJob;
use Modules\Order\Models\Order;
use Illuminate\Support\Facades\DB;

class PacklistService
{
    public function __construct(
        protected PacklistRepository $packlistRepository,
    ) {}

    public function getAllPaginated(int $limit = 10)
    {
        return $this->packlistRepository->getAllPaginated($limit);
    }

    public function getById(string $id): ?Packlist
    {
        return $this->packlistRepository->findById($id);
    }

    public function getItems(string $packlistId, int $limit = 10)
    {
        return $this->packlistRepository->getItemsPaginated($packlistId, $limit);
    }

    public function create(array $data): Packlist
    {
        return DB::transaction(function () use ($data) {
            $order = Order::with('items')->find($data['order_id']);

            if (!$order) {
                throw new \Exception('Order tidak ditemukan.');
            }

            if ($order->status !== 'picked') {
                throw new \Exception("Order harus berstatus 'picked' untuk membuat packlist (saat ini: {$order->status}).");
            }

            $existing = $this->packlistRepository->findByOrderId($order->id);
            if ($existing) {
                throw new \Exception("Order sudah memiliki packlist aktif: {$existing->packlist_no}.");
            }

            $packlistNo = $this->packlistRepository->generatePacklistNo();

            $packlist = $this->packlistRepository->create([
                'packlist_no' => $packlistNo,
                'location_id' => $data['location_id'],
                'packer_id' => $data['packer_id'] ?? null,
                'assigned_by' => isset($data['packer_id']) ? $data['created_by'] : null,
                'order_id' => $order->id,
                'picklist_id' => $data['picklist_id'] ?? null,
                'status' => Packlist::STATUS_DRAFT,
                'notes' => $data['notes'] ?? null,
                'created_by' => $data['created_by'],
            ]);

            foreach ($order->items as $orderItem) {
                $this->packlistRepository->createItem([
                    'packlist_id' => $packlist->id,
                    'order_item_id' => $orderItem->id,
                    'item_id' => $orderItem->item_id,
                    'sku' => $orderItem->sku,
                    'qty_ordered' => $orderItem->qty_in_base,
                    'qty_packed' => 0,
                    'barcode_verified' => false,
                ]);
            }

            return $this->packlistRepository->findById($packlist->id);
        });
    }

    public function assignPacker(string $id, string $packerId, string $assignedBy): Packlist
    {
        $packlist = $this->packlistRepository->findById($id);

        if (!$packlist) {
            throw new \Exception('Packlist tidak ditemukan.');
        }

        if ($packlist->status !== Packlist::STATUS_DRAFT) {
            throw new \Exception("Packer hanya bisa di-assign pada status DRAFT (saat ini: {$packlist->status}).");
        }

        $this->packlistRepository->update($id, [
            'packer_id' => $packerId,
            'assigned_by' => $assignedBy,
        ]);

        return $this->packlistRepository->findById($id);
    }

    public function start(string $id): Packlist
    {
        $packlist = $this->packlistRepository->findById($id);

        if (!$packlist) {
            throw new \Exception('Packlist tidak ditemukan.');
        }

        if ($packlist->status !== Packlist::STATUS_DRAFT) {
            throw new \Exception("Hanya packlist DRAFT yang bisa dimulai (saat ini: {$packlist->status}).");
        }

        $this->packlistRepository->update($id, [
            'status' => Packlist::STATUS_IN_PROGRESS,
            'started_at' => now(),
        ]);

        return $this->packlistRepository->findById($id);
    }

    public function packItem(string $packlistId, string $itemId, array $data): void
    {
        $packlist = $this->packlistRepository->findById($packlistId);

        if (!$packlist) {
            throw new \Exception('Packlist tidak ditemukan.');
        }

        if (!in_array($packlist->status, [Packlist::STATUS_DRAFT, Packlist::STATUS_IN_PROGRESS])) {
            throw new \Exception("Packlist tidak bisa di-pack (status saat ini: {$packlist->status}).");
        }

        $item = $packlist->items->firstWhere('id', $itemId);
        if (!$item) {
            throw new \Exception('Item packlist tidak ditemukan.');
        }

        if ($data['qty_packed'] > $item->qty_ordered) {
            throw new \Exception("Qty packed ({$data['qty_packed']}) melebihi qty ordered ({$item->qty_ordered}).");
        }

        $this->packlistRepository->updateItem($itemId, [
            'qty_packed' => $data['qty_packed'],
            'barcode_verified' => $data['barcode_verified'] ?? $item->barcode_verified,
        ]);
    }

    public function verifyBarcode(string $packlistId, string $barcode): array
    {
        $packlist = $this->packlistRepository->findById($packlistId);

        if (!$packlist) {
            throw new \Exception('Packlist tidak ditemukan.');
        }

        $item = $packlist->items->first(fn ($i) => $i->sku === $barcode);

        if (!$item) {
            throw new \Exception("Barcode/SKU '{$barcode}' tidak ditemukan dalam packlist ini.");
        }

        $this->packlistRepository->updateItem($item->id, ['barcode_verified' => true]);

        return ['item_id' => $item->id, 'sku' => $item->sku, 'verified' => true];
    }

    public function complete(string $id): Packlist
    {
        $packlist = $this->packlistRepository->findById($id);

        if (!$packlist) {
            throw new \Exception('Packlist tidak ditemukan.');
        }

        if (!in_array($packlist->status, [Packlist::STATUS_DRAFT, Packlist::STATUS_IN_PROGRESS])) {
            throw new \Exception("Hanya packlist DRAFT/IN_PROGRESS yang bisa di-complete (saat ini: {$packlist->status}).");
        }

        $unpacked = $packlist->items->filter(fn ($item) => $item->qty_packed < $item->qty_ordered);
        if ($unpacked->isNotEmpty()) {
            throw new \Exception("Masih ada {$unpacked->count()} item yang belum selesai di-pack.");
        }

        $this->packlistRepository->update($id, [
            'status' => Packlist::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);

        ProcessPacklistCompleteJob::dispatch($id);

        return $this->packlistRepository->findById($id);
    }

    public function cancel(string $id): Packlist
    {
        $packlist = $this->packlistRepository->findById($id);

        if (!$packlist) {
            throw new \Exception('Packlist tidak ditemukan.');
        }

        if ($packlist->status === Packlist::STATUS_COMPLETED) {
            throw new \Exception('Packlist yang sudah complete tidak bisa di-cancel.');
        }

        $this->packlistRepository->update($id, [
            'status' => Packlist::STATUS_CANCELLED,
        ]);

        return $this->packlistRepository->findById($id);
    }

    public function delete(string $id): bool
    {
        $packlist = $this->packlistRepository->findById($id);

        if (!$packlist) {
            throw new \Exception('Packlist tidak ditemukan.');
        }

        if ($packlist->status !== Packlist::STATUS_DRAFT) {
            throw new \Exception("Hanya packlist DRAFT yang bisa dihapus (saat ini: {$packlist->status}).");
        }

        return $this->packlistRepository->delete($id);
    }
}
