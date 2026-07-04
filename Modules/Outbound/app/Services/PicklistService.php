<?php

namespace Modules\Outbound\Services;

use Modules\Outbound\Repositories\PicklistRepository;
use Modules\Outbound\Models\Picklist;
use Modules\Outbound\Models\PicklistItem;
use Modules\Outbound\Jobs\ProcessPicklistCompleteJob;
use Modules\Outbound\Exceptions\OutboundValidationException;
use Modules\Notification\Events\TaskAssigned;
use Modules\Sales\Models\SalesOrder as Order;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Repositories\InventoryMovementRepository;
use Modules\Inventory\Services\InventoryService;
use Modules\Warehouse\Models\LocationBin;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PicklistService
{
    public function __construct(
        protected PicklistRepository $picklistRepository,
        protected InventoryMovementRepository $movementRepository,
        protected InventoryService $inventoryService,
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
                    $this->picklistRepository->createItem([
                        'picklist_id' => $picklist->id,
                        'order_id' => $order->id,
                        'order_item_id' => $orderItem->id,
                        'item_id' => $orderItem->item_id,
                        'sku' => $orderItem->sku,
                        'bin_id' => null,
                        'qty_ordered' => $orderItem->qty_in_base,
                        'qty_picked' => 0,
                    ]);
                }
            }

            $picklist = $this->picklistRepository->findById($picklist->id);

            if (!empty($data['picker_id'])) {
                TaskAssigned::dispatch(
                    $data['picker_id'],
                    'picklist',
                    $picklist->picklist_no,
                    $data['created_by'],
                    ['picklist_id' => $picklist->id],
                );
            }

            return $picklist;
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

        $picklist = $this->picklistRepository->findById($id);

        TaskAssigned::dispatch(
            $pickerId,
            'picklist',
            $picklist->picklist_no,
            $assignedBy,
            ['picklist_id' => $id],
        );

        return $picklist;
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

        $bin = $this->resolveBin($picklist, $data['bin_code']);

        $delta = $data['qty_picked'] - $item->qty_picked;

        if ($delta > 0) {
            DB::transaction(function () use ($item, $bin, $picklist, $data, $delta, $itemId) {
                $inventory = Inventory::where('item_id', $item->item_id)
                    ->where('location_id', $picklist->location_id)
                    ->where('bin_id', $bin->id)
                    ->lockForUpdate()
                    ->first();

                if (!$inventory) {
                    throw new \Exception("SKU ini tidak ditemukan di rak {$bin->bin_final_code}. Silahkan pilih rak lain.");
                }

                if ($inventory->on_hand < $delta) {
                    throw new \Exception("Stok tidak cukup di rak {$bin->bin_final_code}. Tersedia: {$inventory->on_hand}, dibutuhkan: {$delta}. Silahkan pilih rak lain.");
                }

                $inventory->on_hand -= $delta;
                $inventory->reserved = max(0, $inventory->reserved - $delta);
                $inventory->recalculateAvailable();
                $inventory->save();

                $this->picklistRepository->updateItem($itemId, [
                    'qty_picked' => $data['qty_picked'],
                    'bin_id' => $bin->id,
                ]);

                $this->movementRepository->create([
                    'item_id'            => $item->item_id,
                    'location_id'        => $picklist->location_id,
                    'bin_id'             => $bin->id,
                    'transaction_number' => $picklist->picklist_no,
                    'source'             => 'PICKING',
                    'qty'                => -$delta,
                    'balance'            => $inventory->on_hand,
                    'transaction_date'   => now(),
                    'created_by'         => (string) (Auth::id() ?? $picklist->picker_id ?? 'system'),
                ]);
            });
        } elseif ($delta < 0) {
            // Koreksi turun: kembalikan stok yang sudah di-pick ke rak asalnya.
            // Rak asal = bin tempat item terakhir di-pick (item->bin_id), fallback ke bin yang di-scan.
            $returnQty = -$delta;
            $returnBinId = $item->bin_id ?? $bin->id;

            $this->inventoryService->reversePick([
                'item_id'            => $item->item_id,
                'location_id'        => $picklist->location_id,
                'bin_id'             => $returnBinId,
                'qty'                => $returnQty,
                'transaction_number' => $picklist->picklist_no . '-KOREKSI',
                'created_by'         => (string) (Auth::id() ?? $picklist->picker_id ?? 'system'),
            ]);

            $this->picklistRepository->updateItem($itemId, [
                'qty_picked' => $data['qty_picked'],
            ]);

            $this->revertPicklistCompletion($picklistId);
        } else {
            // delta == 0: hanya perbarui rak.
            $this->picklistRepository->updateItem($itemId, [
                'bin_id' => $bin->id,
            ]);
        }
    }

    /**
     * Koreksi salah scan pick: batalkan (sebagian/seluruh) qty yang sudah di-pick pada satu baris,
     * kembalikan stok ke rak asalnya.
     */
    public function unpickItem(string $picklistId, string $itemId, ?int $qty, string $userId): Picklist
    {
        return $this->unpickItems($picklistId, [
            ['item_id' => $itemId, 'qty' => $qty],
        ], $userId);
    }

    /**
     * Koreksi massal: batalkan pick beberapa baris sekaligus dalam 1 transaksi.
     * $items = [['item_id' => ..., 'qty' => ?int], ...]
     */
    public function unpickItems(string $picklistId, array $items, string $userId): Picklist
    {
        if (empty($items)) {
            throw new OutboundValidationException('Tidak ada baris yang dipilih untuk dikoreksi.');
        }

        return DB::transaction(function () use ($picklistId, $items, $userId) {
            $picklist = $this->picklistRepository->findById($picklistId);

            if (! $picklist) {
                throw new \Exception('Picklist tidak ditemukan.');
            }

            if (! in_array($picklist->status, [Picklist::STATUS_DRAFT, Picklist::STATUS_IN_PROGRESS, Picklist::STATUS_COMPLETED], true)) {
                throw new OutboundValidationException("Picklist tidak bisa dikoreksi (status saat ini: {$picklist->status}).");
            }

            foreach ($items as $entry) {
                $itemId = $entry['item_id'] ?? null;
                $qty = $entry['qty'] ?? null;

                if (! $itemId) {
                    throw new OutboundValidationException('item_id wajib diisi.');
                }

                $item = PicklistItem::where('picklist_id', $picklistId)
                    ->where('id', $itemId)
                    ->lockForUpdate()
                    ->first();

                if (! $item) {
                    throw new OutboundValidationException('Item picklist tidak ditemukan.');
                }

                $qtyRev = $qty ?? (int) $item->qty_picked;

                if ($qtyRev <= 0 || $qtyRev > (int) $item->qty_picked) {
                    throw new OutboundValidationException("Qty koreksi tidak valid (maksimal {$item->qty_picked}).");
                }

                if (empty($item->bin_id)) {
                    throw new OutboundValidationException('Baris ini tidak punya rak asal pick, tidak bisa dikoreksi.');
                }

                $this->inventoryService->reversePick([
                    'item_id'            => $item->item_id,
                    'location_id'        => $picklist->location_id,
                    'bin_id'             => $item->bin_id,
                    'qty'                => $qtyRev,
                    'transaction_number' => $picklist->picklist_no . '-KOREKSI',
                    'created_by'         => (string) ($userId ?: 'system'),
                ]);

                $this->picklistRepository->updateItem($itemId, [
                    'qty_picked' => max(0, (int) $item->qty_picked - $qtyRev),
                ]);
            }

            $this->revertPicklistCompletion($picklistId);

            return $this->picklistRepository->findById($picklistId);
        });
    }

    private function revertPicklistCompletion(string $picklistId): void
    {
        $picklist = $this->picklistRepository->findById($picklistId);

        if ($picklist && $picklist->status === Picklist::STATUS_COMPLETED) {
            $this->picklistRepository->update($picklistId, [
                'status' => Picklist::STATUS_IN_PROGRESS,
                'completed_at' => null,
            ]);
        }
    }

    public function scanForPick(string $picklistId, string $sku, ?string $binCode = null, ?string $hintActiveBinCode = null): array
    {
        $picklist = $this->picklistRepository->findById($picklistId);

        if (!$picklist) {
            throw new OutboundValidationException('Picklist tidak ditemukan.');
        }

        if (!in_array($picklist->status, [Picklist::STATUS_DRAFT, Picklist::STATUS_IN_PROGRESS])) {
            throw new OutboundValidationException("Picklist tidak bisa di-pick (status saat ini: {$picklist->status}).");
        }

        $item = $this->resolveItemBySku($picklist, $sku);

        if ($item->qty_picked >= $item->qty_ordered) {
            throw new OutboundValidationException("{$item->sku} sudah selesai di-pick.");
        }

        $remaining = $item->qty_ordered - $item->qty_picked;

        if ($binCode !== null && $binCode !== '') {
            $bin = $this->resolveBin($picklist, $binCode);
            $available = $this->assertInventoryForPick($item, $picklist->location_id, $bin);

            return [
                'item_id' => $item->id,
                'sku' => $item->sku,
                'bin_code' => $bin->bin_final_code,
                'available_in_bin' => $available,
                'remaining_to_pick' => $remaining,
                'max_pickable' => min($available, $remaining),
                'bin_source' => 'manual',
                'candidates' => [],
            ];
        }

        $candidates = $this->suggestBinsForItem($picklist, $item);

        $default = $this->pickDefaultCandidate($candidates, $remaining, $hintActiveBinCode);
        $binSource = $hintActiveBinCode !== null && strcasecmp($default['bin_code'], $hintActiveBinCode) === 0
            ? 'hint'
            : 'auto';

        return [
            'item_id' => $item->id,
            'sku' => $item->sku,
            'bin_code' => $default['bin_code'],
            'available_in_bin' => $default['on_hand'],
            'remaining_to_pick' => $remaining,
            'max_pickable' => min($default['on_hand'], $remaining),
            'bin_source' => $binSource,
            'candidates' => $candidates->values()->all(),
        ];
    }

    /**
     * Validasi (item, location, bin, on_hand > 0) tanpa mutasi. Sumber kebenaran tunggal
     * untuk jalur scan manual & suggest. Race-safety commit tetap di pickItem() lockForUpdate.
     */
    private function assertInventoryForPick(PicklistItem $item, string $locationId, LocationBin $bin): int
    {
        $inventory = Inventory::where('item_id', $item->item_id)
            ->where('location_id', $locationId)
            ->where('bin_id', $bin->id)
            ->first();

        if (!$inventory) {
            throw new OutboundValidationException("SKU ini tidak ditemukan di rak {$bin->bin_final_code}. Silahkan pilih rak lain.");
        }

        $available = (int) $inventory->on_hand;

        if ($available <= 0) {
            throw new OutboundValidationException("Stok tidak cukup di rak {$bin->bin_final_code}. Tersedia: {$available}. Silahkan pilih rak lain.");
        }

        return $available;
    }

    /**
     * Cari semua bin (di lokasi picklist) yang menyimpan item ini dengan on_hand > 0.
     * Urutan FIFO by inventory.created_at. Tolak kalau kosong (butuh replenishment).
     */
    private function suggestBinsForItem(Picklist $picklist, PicklistItem $item): \Illuminate\Support\Collection
    {
        $rows = Inventory::where('item_id', $item->item_id)
            ->where('location_id', $picklist->location_id)
            ->where('on_hand', '>', 0)
            ->with(['bin:id,bin_final_code'])
            ->orderBy('created_at')
            ->get();

        $candidates = $rows
            ->filter(fn ($inv) => $inv->bin !== null)
            ->map(fn ($inv) => [
                'bin_id' => $inv->bin_id,
                'bin_code' => $inv->bin->bin_final_code,
                'on_hand' => (int) $inv->on_hand,
            ]);

        if ($candidates->isEmpty()) {
            throw new OutboundValidationException("Stok {$item->sku} kosong di gudang ini. Butuh replenishment.");
        }

        return $candidates;
    }

    /**
     * Prioritas default candidate: (1) hint bin yang qty-nya cukup, (2) hint bin apapun,
     * (3) kandidat pertama yang on_hand >= remaining, (4) kandidat pertama FIFO.
     * @param \Illuminate\Support\Collection<int, array{bin_id:string,bin_code:string,on_hand:int}> $candidates
     * @return array{bin_id:string,bin_code:string,on_hand:int}
     */
    private function pickDefaultCandidate(\Illuminate\Support\Collection $candidates, int $remaining, ?string $hintActiveBinCode): array
    {
        if ($hintActiveBinCode !== null && $hintActiveBinCode !== '') {
            $hinted = $candidates->first(fn ($c) => strcasecmp($c['bin_code'], $hintActiveBinCode) === 0);
            if ($hinted && $hinted['on_hand'] >= $remaining) {
                return $hinted;
            }
            if ($hinted) {
                return $hinted;
            }
        }

        $enough = $candidates->first(fn ($c) => $c['on_hand'] >= $remaining);
        if ($enough) {
            return $enough;
        }

        return $candidates->first();
    }

    private function resolveItemBySku(Picklist $picklist, string $sku): PicklistItem
    {
        $lower = mb_strtolower($sku);
        $items = $picklist->items;

        $item = $items->first(fn ($it) => mb_strtolower($it->sku) === $lower && $it->qty_picked < $it->qty_ordered)
            ?? $items->first(fn ($it) => mb_strtolower($it->sku) === $lower)
            ?? $items->first(fn ($it) => str_contains(mb_strtolower($it->sku ?? ''), $lower) && $it->qty_picked < $it->qty_ordered);

        if (!$item) {
            throw new OutboundValidationException("SKU {$sku} tidak ada di picklist ini.");
        }

        return $item;
    }

    private function resolveBin(Picklist $picklist, string $binCode): LocationBin
    {
        $isSku = $picklist->items->contains(fn ($i) => strcasecmp($i->sku, $binCode) === 0);
        if ($isSku) {
            throw new OutboundValidationException("'{$binCode}' adalah SKU produk, bukan kode rak.");
        }

        $bin = LocationBin::where('bin_final_code', $binCode)
            ->where('location_id', $picklist->location_id)
            ->first();

        if (!$bin) {
            throw new OutboundValidationException("Rak dengan kode '{$binCode}' tidak ditemukan.");
        }

        return $bin;
    }

    public function complete(string $id): Picklist
    {
        $picklist = $this->picklistRepository->findById($id);

        if (!$picklist) {
            throw new \Exception('Picklist tidak ditemukan.');
        }

        if (!in_array($picklist->status, [Picklist::STATUS_DRAFT, Picklist::STATUS_IN_PROGRESS])) {
            throw new OutboundValidationException("Hanya picklist DRAFT/IN_PROGRESS yang bisa di-complete (saat ini: {$picklist->status}).");
        }

        $unpicked = $picklist->items->filter(fn ($item) => $item->qty_picked < $item->qty_ordered);
        if ($unpicked->isNotEmpty()) {
            throw new OutboundValidationException("Masih ada {$unpicked->count()} item yang belum selesai di-pick.");
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
