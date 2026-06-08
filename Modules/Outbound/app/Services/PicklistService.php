<?php

namespace Modules\Outbound\Services;

use Modules\Outbound\Repositories\PicklistRepository;
use Modules\Outbound\Models\Picklist;
use Modules\Outbound\Jobs\ProcessPicklistCompleteJob;
use Modules\Order\Models\Order;
use Modules\Inventory\Models\Inventory;
use Illuminate\Support\Facades\DB;

class PicklistService
{
    public function __construct(
        protected PicklistRepository $picklistRepository,
    ) {}

    public function getAllPaginated(int $limit = 10)
    {
        return $this->picklistRepository->getAllPaginated($limit);
    }

    public function getById(string $id): ?Picklist
    {
        return $this->picklistRepository->findById($id);
    }

    public function getItems(string $picklistId, int $limit = 10)
    {
        return $this->picklistRepository->getItemsPaginated($picklistId, $limit);
    }

    public function create(array $data): Picklist
    {
        return DB::transaction(function () use ($data) {
            $picklistNo = $this->picklistRepository->generatePicklistNo();

            $picklist = $this->picklistRepository->create([
                'picklist_no' => $picklistNo,
                'location_id' => $data['location_id'],
                'picker_id' => $data['picker_id'] ?? null,
                'assigned_by' => isset($data['picker_id']) ? $data['created_by'] : null,
                'status' => Picklist::STATUS_DRAFT,
                'notes' => $data['notes'] ?? null,
                'created_by' => $data['created_by'],
            ]);

            $orders = Order::with('items')
                ->whereIn('id', $data['order_ids'])
                ->where('status', 'reserved')
                ->get();

            if ($orders->isEmpty()) {
                throw new \Exception('Tidak ada order dengan status reserved yang ditemukan.');
            }

            foreach ($orders as $order) {
                foreach ($order->items as $orderItem) {
                    $bin = Inventory::where('item_id', $orderItem->item_id)
                        ->where('location_id', $data['location_id'])
                        ->where('on_hand', '>', 0)
                        ->orderByDesc('on_hand')
                        ->value('bin_id');

                    $this->picklistRepository->createItem([
                        'picklist_id' => $picklist->id,
                        'order_id' => $order->id,
                        'order_item_id' => $orderItem->id,
                        'item_id' => $orderItem->item_id,
                        'sku' => $orderItem->sku,
                        'bin_id' => $bin,
                        'qty_ordered' => $orderItem->qty_in_base,
                        'qty_picked' => 0,
                    ]);
                }
            }

            return $this->picklistRepository->findById($picklist->id);
        });
    }

    public function assignPicker(string $id, string $pickerId, string $assignedBy): Picklist
    {
        $picklist = $this->picklistRepository->findById($id);

        if (!$picklist) {
            throw new \Exception('Picklist tidak ditemukan.');
        }

        if ($picklist->status !== Picklist::STATUS_DRAFT) {
            throw new \Exception("Picker hanya bisa di-assign pada status DRAFT (saat ini: {$picklist->status}).");
        }

        $this->picklistRepository->update($id, [
            'picker_id' => $pickerId,
            'assigned_by' => $assignedBy,
        ]);

        return $this->picklistRepository->findById($id);
    }

    public function start(string $id): Picklist
    {
        $picklist = $this->picklistRepository->findById($id);

        if (!$picklist) {
            throw new \Exception('Picklist tidak ditemukan.');
        }

        if ($picklist->status !== Picklist::STATUS_DRAFT) {
            throw new \Exception("Hanya picklist DRAFT yang bisa dimulai (saat ini: {$picklist->status}).");
        }

        $this->picklistRepository->update($id, [
            'status' => Picklist::STATUS_IN_PROGRESS,
            'started_at' => now(),
        ]);

        return $this->picklistRepository->findById($id);
    }

    public function pickItem(string $picklistId, string $itemId, array $data): void
    {
        $picklist = $this->picklistRepository->findById($picklistId);

        if (!$picklist) {
            throw new \Exception('Picklist tidak ditemukan.');
        }

        if (!in_array($picklist->status, [Picklist::STATUS_DRAFT, Picklist::STATUS_IN_PROGRESS])) {
            throw new \Exception("Picklist tidak bisa di-pick (status saat ini: {$picklist->status}).");
        }

        $item = $picklist->items->firstWhere('id', $itemId);
        if (!$item) {
            throw new \Exception('Item picklist tidak ditemukan.');
        }

        if ($data['qty_picked'] > $item->qty_ordered) {
            throw new \Exception("Qty picked ({$data['qty_picked']}) melebihi qty ordered ({$item->qty_ordered}).");
        }

        $updateData = ['qty_picked' => $data['qty_picked']];
        if (isset($data['bin_id'])) {
            $updateData['bin_id'] = $data['bin_id'];
        }

        $this->picklistRepository->updateItem($itemId, $updateData);
    }

    public function complete(string $id): Picklist
    {
        $picklist = $this->picklistRepository->findById($id);

        if (!$picklist) {
            throw new \Exception('Picklist tidak ditemukan.');
        }

        if (!in_array($picklist->status, [Picklist::STATUS_DRAFT, Picklist::STATUS_IN_PROGRESS])) {
            throw new \Exception("Hanya picklist DRAFT/IN_PROGRESS yang bisa di-complete (saat ini: {$picklist->status}).");
        }

        $unpicked = $picklist->items->filter(fn ($item) => $item->qty_picked < $item->qty_ordered);
        if ($unpicked->isNotEmpty()) {
            throw new \Exception("Masih ada {$unpicked->count()} item yang belum selesai di-pick.");
        }

        $this->picklistRepository->update($id, [
            'status' => Picklist::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);

        ProcessPicklistCompleteJob::dispatch($id);

        return $this->picklistRepository->findById($id);
    }

    public function failPick(string $id, ?string $reason = null): Picklist
    {
        $picklist = $this->picklistRepository->findById($id);

        if (!$picklist) {
            throw new \Exception('Picklist tidak ditemukan.');
        }

        if (!in_array($picklist->status, [Picklist::STATUS_DRAFT, Picklist::STATUS_IN_PROGRESS])) {
            throw new \Exception("Hanya picklist DRAFT/IN_PROGRESS yang bisa di-fail (saat ini: {$picklist->status}).");
        }

        $this->picklistRepository->update($id, [
            'status' => Picklist::STATUS_FAILED,
            'notes' => $reason ? ($picklist->notes ? $picklist->notes . ' | FAILED: ' . $reason : 'FAILED: ' . $reason) : $picklist->notes,
        ]);

        return $this->picklistRepository->findById($id);
    }

    public function cancel(string $id): Picklist
    {
        $picklist = $this->picklistRepository->findById($id);

        if (!$picklist) {
            throw new \Exception('Picklist tidak ditemukan.');
        }

        if ($picklist->status === Picklist::STATUS_COMPLETED) {
            throw new \Exception('Picklist yang sudah complete tidak bisa di-cancel.');
        }

        $this->picklistRepository->update($id, [
            'status' => Picklist::STATUS_CANCELLED,
        ]);

        return $this->picklistRepository->findById($id);
    }

    public function delete(string $id): bool
    {
        $picklist = $this->picklistRepository->findById($id);

        if (!$picklist) {
            throw new \Exception('Picklist tidak ditemukan.');
        }

        if ($picklist->status !== Picklist::STATUS_DRAFT) {
            throw new \Exception("Hanya picklist DRAFT yang bisa dihapus (saat ini: {$picklist->status}).");
        }

        return $this->picklistRepository->delete($id);
    }
}
