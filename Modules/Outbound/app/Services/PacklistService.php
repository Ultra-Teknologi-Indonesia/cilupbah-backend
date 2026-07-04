<?php

namespace Modules\Outbound\Services;

use Modules\Outbound\Repositories\PacklistRepository;
use Modules\Outbound\Models\Packlist;
use Modules\Outbound\Models\PacklistItem;
use Modules\Outbound\Jobs\ProcessPacklistCompleteJob;
use Modules\Outbound\Exceptions\OutboundValidationException;
use Modules\Notification\Events\TaskAssigned;
use Modules\Sales\Models\SalesOrder as Order;
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

    public function scanOrder(string $orderNo, ?string $packerId = null, ?string $createdBy = null): ?Packlist
    {
        $order = Order::where('salesorder_no', $orderNo)
            ->orWhere('channel_order_no', $orderNo)
            ->orWhere('tracking_number', $orderNo)
            ->first();

        if (!$order) {
            return null;
        }

        $packlist = Packlist::where('order_id', $order->id)
            ->whereIn('status', [Packlist::STATUS_DRAFT, Packlist::STATUS_IN_PROGRESS])
            ->first();

        if (!$packlist && $order->status === 'picked') {
            $packlist = $this->create([
                'order_id' => $order->id,
                'location_id' => $order->location_id,
                'packer_id' => $packerId,
                'created_by' => $createdBy ?? 'system',
            ]);
        }

        if (!$packlist) {
            return null;
        }

        if ($packlist->status === Packlist::STATUS_DRAFT) {
            $packlist->update([
                'status' => Packlist::STATUS_IN_PROGRESS,
                'started_at' => now(),
            ]);
            $packlist->refresh();
        }

        return $packlist->load([
            'items.product:id,sku,product_id',
            'items.product.media:id,variant_id,product_id,url,is_primary,sort_order',
            'items.product.product:id,name',
            'items.product.product.media:id,product_id,variant_id,url,is_primary,sort_order',
            'items.orderItem:id,sku,description,item_id',
            'items.orderItem.product:id,sku,product_id',
            'items.orderItem.product.media:id,variant_id,product_id,url,is_primary,sort_order',
            'items.orderItem.product.product:id,name',
            'items.orderItem.product.product.media:id,product_id,variant_id,url,is_primary,sort_order',
            'location:id,location_name,location_code',
            'packer:id,name,email',
            'order:id,salesorder_no,customer_name',
        ]);
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

            $packlist = $this->packlistRepository->findById($packlist->id);

            if (!empty($data['packer_id'])) {
                TaskAssigned::dispatch(
                    $data['packer_id'],
                    'packlist',
                    $packlist->packlist_no,
                    $data['created_by'],
                    ['packlist_id' => $packlist->id],
                );
            }

            return $packlist;
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

        $packlist = $this->packlistRepository->findById($id);

        TaskAssigned::dispatch(
            $packerId,
            'packlist',
            $packlist->packlist_no,
            $assignedBy,
            ['packlist_id' => $id],
        );

        return $packlist;
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

    /**
     * Koreksi salah scan pack: kurangi qty_packed pada satu baris.
     * Packing tidak mengubah stok, jadi cukup menurunkan qty_packed (+ reset verifikasi barcode).
     */
    public function unpackItem(string $packlistId, string $itemId, ?int $qty): void
    {
        $this->unpackItems($packlistId, [
            ['item_id' => $itemId, 'qty' => $qty],
        ]);
    }

    /**
     * Koreksi massal: kurangi qty_packed beberapa baris sekaligus dalam 1 transaksi.
     * $items = [['item_id' => ..., 'qty' => ?int], ...]
     */
    public function unpackItems(string $packlistId, array $items): void
    {
        if (empty($items)) {
            throw new \Exception('Tidak ada baris yang dipilih untuk dikoreksi.');
        }

        DB::transaction(function () use ($packlistId, $items) {
            $packlist = $this->packlistRepository->findById($packlistId);

            if (! $packlist) {
                throw new \Exception('Packlist tidak ditemukan.');
            }

            if (! in_array($packlist->status, [Packlist::STATUS_DRAFT, Packlist::STATUS_IN_PROGRESS], true)) {
                throw new \Exception("Packlist tidak bisa dikoreksi (status saat ini: {$packlist->status}).");
            }

            $itemsDict = $packlist->items->keyBy('id');

            foreach ($items as $entry) {
                $itemId = $entry['item_id'] ?? null;
                $qty = $entry['qty'] ?? null;

                if (! $itemId) {
                    throw new \Exception('item_id wajib diisi.');
                }

                $item = $itemsDict->get($itemId);
                if (! $item) {
                    throw new \Exception('Item packlist tidak ditemukan.');
                }

                $qtyRev = $qty ?? (int) $item->qty_packed;

                if ($qtyRev <= 0 || $qtyRev > (int) $item->qty_packed) {
                    throw new \Exception("Qty koreksi tidak valid (maksimal {$item->qty_packed}).");
                }

                $newQty = max(0, (int) $item->qty_packed - $qtyRev);

                $this->packlistRepository->updateItem($itemId, [
                    'qty_packed' => $newQty,
                    'barcode_verified' => $newQty > 0 ? $item->barcode_verified : false,
                ]);
            }
        });
    }

    public function verifyBarcode(string $packlistId, string $barcode): array
    {
        $item = PacklistItem::where('packlist_id', $packlistId)
            ->where('sku', $barcode)
            ->first();

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
            throw new OutboundValidationException("Hanya packlist DRAFT/IN_PROGRESS yang bisa di-complete (saat ini: {$packlist->status}).");
        }

        $unpacked = $packlist->items->filter(fn ($item) => $item->qty_packed < $item->qty_ordered);
        if ($unpacked->isNotEmpty()) {
            throw new OutboundValidationException("Masih ada {$unpacked->count()} item yang belum selesai di-pack.");
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
