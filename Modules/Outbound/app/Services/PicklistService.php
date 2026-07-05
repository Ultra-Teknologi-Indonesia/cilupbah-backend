<?php

namespace Modules\Outbound\Services;

use Modules\Outbound\Repositories\PicklistRepository;
use Modules\Outbound\Models\Picklist;
use Modules\Outbound\Models\PicklistItem;
use Modules\Outbound\Models\PicklistItemAllocation;
use Modules\Outbound\Models\Packlist;
use Modules\Outbound\Jobs\ProcessPicklistCompleteJob;
use Modules\Outbound\Exceptions\OutboundValidationException;
use Modules\Outbound\Events\PicklistItemFailed;
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
                $userId = (string) (Auth::id() ?? $picklist->picker_id ?? 'system');

                $this->commitPickAllocation($picklist, $item, $bin, $delta, $userId);

                $this->picklistRepository->updateItem($itemId, [
                    'qty_picked' => $data['qty_picked'],
                    'bin_id' => $bin->id,
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

        $unfinished = $picklist->items->filter(function ($item) {
            $picked = (int) $item->qty_picked;
            $ordered = (int) $item->qty_ordered;
            if ($picked >= $ordered) {
                return false;
            }
            $status = $item->item_status;
            return !in_array($status, [PicklistItem::STATUS_SHORT, PicklistItem::STATUS_REJECTED], true);
        });
        if ($unfinished->isNotEmpty()) {
            throw new OutboundValidationException("Masih ada {$unfinished->count()} item yang belum di-pick atau di-fail.");
        }

        $this->picklistRepository->update($id, [
            'status' => Picklist::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);

        ProcessPicklistCompleteJob::dispatch($id);

        return $this->picklistRepository->findById($id);
    }

    /**
     * Mark a single picklist item as failed (SHORT/REJECTED). No inventory mutation.
     */
    public function failPickItem(string $picklistId, string $itemId, string $reasonCode, ?string $reasonNote, string $userId): Picklist
    {
        $reasonCode = strtoupper($reasonCode);
        $allowed = [
            PicklistItem::REASON_STOCK_EMPTY,
            PicklistItem::REASON_DAMAGED,
            PicklistItem::REASON_REJECTED,
            PicklistItem::REASON_MISSING,
            PicklistItem::REASON_OTHER,
        ];
        if (!in_array($reasonCode, $allowed, true)) {
            throw new OutboundValidationException("Alasan gagal tidak valid: {$reasonCode}.");
        }

        return DB::transaction(function () use ($picklistId, $itemId, $reasonCode, $reasonNote, $userId) {
            $picklist = $this->picklistRepository->findById($picklistId);

            if (!$picklist) {
                throw new OutboundValidationException('Picklist tidak ditemukan.');
            }

            if (!in_array($picklist->status, [Picklist::STATUS_DRAFT, Picklist::STATUS_IN_PROGRESS], true)) {
                throw new OutboundValidationException("Item hanya bisa di-fail saat picklist DRAFT/IN_PROGRESS (saat ini: {$picklist->status}).");
            }

            $item = PicklistItem::where('picklist_id', $picklistId)
                ->where('id', $itemId)
                ->lockForUpdate()
                ->first();

            if (!$item) {
                throw new OutboundValidationException('Item picklist tidak ditemukan.');
            }

            if ((int) $item->qty_picked >= (int) $item->qty_ordered) {
                throw new OutboundValidationException('Item sudah selesai di-pick. Batalkan pick dulu (unpick) sebelum tandai gagal.');
            }

            // Reject → REJECTED; Damaged → REJECTED; else (STOCK_EMPTY, MISSING, OTHER) → SHORT.
            $itemStatus = in_array($reasonCode, [PicklistItem::REASON_DAMAGED, PicklistItem::REASON_REJECTED], true)
                ? PicklistItem::STATUS_REJECTED
                : PicklistItem::STATUS_SHORT;

            $failedQty = (int) $item->qty_ordered - (int) $item->qty_picked;

            $this->picklistRepository->updateItem($itemId, [
                'item_status'      => $itemStatus,
                'fail_reason_code' => $reasonCode,
                'fail_reason_note' => $reasonNote,
                'failed_qty'       => $failedQty,
                'failed_at'        => now(),
                'failed_by'        => $userId ?: null,
            ]);

            PicklistItemFailed::dispatch($picklistId, $itemId, $reasonCode, $failedQty, (string) ($userId ?: 'system'));

            return $this->picklistRepository->findById($picklistId);
        });
    }

    /**
     * Undo a fail flag on a single picklist item. Restores item_status to null (derive).
     */
    public function unfailPickItem(string $picklistId, string $itemId, string $userId): Picklist
    {
        return DB::transaction(function () use ($picklistId, $itemId) {
            $picklist = $this->picklistRepository->findById($picklistId);

            if (!$picklist) {
                throw new OutboundValidationException('Picklist tidak ditemukan.');
            }

            if (!in_array($picklist->status, [Picklist::STATUS_DRAFT, Picklist::STATUS_IN_PROGRESS], true)) {
                throw new OutboundValidationException("Undo fail hanya bisa saat picklist DRAFT/IN_PROGRESS (saat ini: {$picklist->status}).");
            }

            $item = PicklistItem::where('picklist_id', $picklistId)
                ->where('id', $itemId)
                ->lockForUpdate()
                ->first();

            if (!$item) {
                throw new OutboundValidationException('Item picklist tidak ditemukan.');
            }

            $this->picklistRepository->updateItem($itemId, [
                'item_status'      => null,
                'fail_reason_code' => null,
                'fail_reason_note' => null,
                'failed_qty'       => null,
                'failed_at'        => null,
                'failed_by'        => null,
            ]);

            return $this->picklistRepository->findById($picklistId);
        });
    }

    /**
     * Pick a single item across multiple bins in one atomic call.
     * $allocations = [['bin_code' => 'LX-BX-KX-RX6', 'qty' => 5], ...]
     */
    public function splitPickItem(string $picklistId, string $itemId, array $allocations, string $userId): Picklist
    {
        if (count($allocations) < 2) {
            throw new OutboundValidationException('Split pick minimal 2 alokasi rak.');
        }

        return DB::transaction(function () use ($picklistId, $itemId, $allocations, $userId) {
            $picklist = $this->picklistRepository->findById($picklistId);

            if (!$picklist) {
                throw new OutboundValidationException('Picklist tidak ditemukan.');
            }

            if (!in_array($picklist->status, [Picklist::STATUS_DRAFT, Picklist::STATUS_IN_PROGRESS], true)) {
                throw new OutboundValidationException("Picklist tidak bisa di-pick (status saat ini: {$picklist->status}).");
            }

            $item = PicklistItem::where('picklist_id', $picklistId)
                ->where('id', $itemId)
                ->lockForUpdate()
                ->first();

            if (!$item) {
                throw new OutboundValidationException('Item picklist tidak ditemukan.');
            }

            $remaining = (int) $item->qty_ordered - (int) $item->qty_picked;
            if ($remaining <= 0) {
                throw new OutboundValidationException('Item sudah selesai di-pick.');
            }

            $sum = 0;
            foreach ($allocations as $alloc) {
                $sum += (int) ($alloc['qty'] ?? 0);
            }

            if ($sum > $remaining) {
                throw new OutboundValidationException("Total qty alokasi ({$sum}) melebihi sisa yang perlu di-pick ({$remaining}).");
            }

            $lastBinId = null;
            $totalNewlyPicked = 0;
            foreach ($allocations as $alloc) {
                $binCode = (string) ($alloc['bin_code'] ?? '');
                $qty = (int) ($alloc['qty'] ?? 0);
                if ($qty <= 0 || $binCode === '') {
                    throw new OutboundValidationException('Alokasi tidak valid.');
                }

                $bin = $this->resolveBin($picklist, $binCode);
                $this->commitPickAllocation($picklist, $item, $bin, $qty, $userId);
                $lastBinId = $bin->id;
                $totalNewlyPicked += $qty;
            }

            $this->picklistRepository->updateItem($itemId, [
                'qty_picked' => (int) $item->qty_picked + $totalNewlyPicked,
                'bin_id'     => $lastBinId,
            ]);

            return $this->picklistRepository->findById($picklistId);
        });
    }

    /**
     * Shared low-level allocation commit: lock inventory row, assert stock, decrement,
     * write movement, and record picklist_item_allocations row.
     *
     * Caller is responsible for wrapping in DB::transaction and updating
     * picklist_items.qty_picked / bin_id.
     */
    private function commitPickAllocation(Picklist $picklist, PicklistItem $item, LocationBin $bin, int $qty, string $userId): void
    {
        if ($qty <= 0) {
            return;
        }

        $inventory = Inventory::where('item_id', $item->item_id)
            ->where('location_id', $picklist->location_id)
            ->where('bin_id', $bin->id)
            ->lockForUpdate()
            ->first();

        if (!$inventory) {
            throw new OutboundValidationException("SKU ini tidak ditemukan di rak {$bin->bin_final_code}. Silahkan pilih rak lain.");
        }

        if ($inventory->on_hand < $qty) {
            throw new OutboundValidationException("Stok tidak cukup di rak {$bin->bin_final_code}. Tersedia: {$inventory->on_hand}, dibutuhkan: {$qty}. Silahkan pilih rak lain.");
        }

        $inventory->on_hand -= $qty;
        $inventory->reserved = max(0, $inventory->reserved - $qty);
        $inventory->recalculateAvailable();
        $inventory->save();

        $movement = $this->movementRepository->create([
            'item_id'            => $item->item_id,
            'location_id'        => $picklist->location_id,
            'bin_id'             => $bin->id,
            'transaction_number' => $picklist->picklist_no,
            'source'             => 'PICKING',
            'qty'                => -$qty,
            'balance'            => $inventory->on_hand,
            'transaction_date'   => now(),
            'created_by'         => $userId ?: 'system',
        ]);

        PicklistItemAllocation::create([
            'picklist_item_id' => $item->id,
            'bin_id'           => $bin->id,
            'qty'              => $qty,
            'picked_at'        => now(),
            'picked_by'        => $userId ?: null,
            'movement_id'      => is_object($movement) ? ($movement->id ?? null) : null,
        ]);
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

    /**
     * Keluarkan satu order dari picking karena barang minus / tidak ada di lokasi
     * (picklist bisa berisi banyak order/batch): reverse stok yang sudah di-pick,
     * lepas item order ini, lalu tandai order MASUK "Gagal Picking"
     * (`pick_failed_*`), TIDAK langsung ke Siap Proses. Admin nanti "Pindahkan ke
     * Siap Proses". Bila picklist jadi kosong, dokumen ikut dihapus.
     * Return: apakah ada stok yang di-reverse (untuk jejak).
     */
    public function failPickOrder(string $picklistId, string $orderId, string $userId, string $reason): bool
    {
        return DB::transaction(function () use ($picklistId, $orderId, $userId, $reason) {
            $picklist = $this->picklistRepository->findById($picklistId);

            if (!$picklist) {
                throw new OutboundValidationException('Picklist tidak ditemukan.');
            }

            $this->assertOrderNotProgressedBeyondPicking($orderId);
            $reversed = $this->reverseAndDetachOrderFromPicklist($picklist, $orderId, $userId);

            $order = Order::find($orderId);
            if ($order) {
                // Kembalikan order ke daftar Pesanan admin (Gagal Picking): clear
                // handed_to_warehouse_at supaya lolos scopeExcludeHandedToWarehouse.
                $order->update([
                    'pick_failed_at'         => now(),
                    'pick_failed_by'         => $userId,
                    'pick_fail_reason'       => $reason,
                    'handed_to_warehouse_at' => null,
                ]);
            }

            if (!PicklistItem::where('picklist_id', $picklistId)->exists()) {
                $this->picklistRepository->delete($picklistId);
            }

            return $reversed;
        });
    }

    /**
     * Hapus seluruh picklist (batch): semua order anggotanya kembali ke 'reserved'
     * (belum dipick), stok yang sudah ter-pick direversal ke rak asal.
     * Ditolak seluruhnya (all-or-nothing) bila ada order yang sudah lanjut ke
     * packing/pengiriman — order tsb harus dikembalikan dari tahap itu dulu.
     */
    public function revert(string $picklistId, string $userId): void
    {
        DB::transaction(function () use ($picklistId, $userId) {
            $picklist = $this->picklistRepository->findById($picklistId);

            if (!$picklist) {
                throw new OutboundValidationException('Picklist tidak ditemukan.');
            }

            $orderIds = PicklistItem::where('picklist_id', $picklistId)
                ->pluck('order_id')
                ->unique()
                ->values();

            foreach ($orderIds as $orderId) {
                $this->assertOrderNotProgressedBeyondPicking($orderId);
            }

            foreach ($orderIds as $orderId) {
                $this->reverseAndDetachOrderFromPicklist($picklist, $orderId, $userId);
            }

            $this->picklistRepository->delete($picklistId);
        });
    }

    private function assertOrderNotProgressedBeyondPicking(string $orderId): void
    {
        $order = Order::find($orderId);

        if (!$order) {
            return;
        }

        if (in_array($order->status, ['packed', 'shipped'], true)) {
            throw new OutboundValidationException(
                "Pesanan {$order->salesorder_no} sudah lanjut ke tahap packing/pengiriman — kembalikan dari tahap tersebut dulu."
            );
        }

        $hasActivePacklist = Packlist::where('order_id', $orderId)
            ->where('status', '!=', Packlist::STATUS_CANCELLED)
            ->exists();

        if ($hasActivePacklist) {
            throw new OutboundValidationException(
                "Pesanan {$order->salesorder_no} sudah memiliki packlist aktif — kembalikan/hapus packlist tersebut dulu."
            );
        }
    }

    private function reverseAndDetachOrderFromPicklist(Picklist $picklist, string $orderId, string $userId): bool
    {
        $items = PicklistItem::where('picklist_id', $picklist->id)
            ->where('order_id', $orderId)
            ->lockForUpdate()
            ->get();

        $reversed = false;

        foreach ($items as $item) {
            if ((int) $item->qty_picked <= 0) {
                continue;
            }

            if (empty($item->bin_id)) {
                throw new OutboundValidationException('Baris ini tidak punya rak asal pick, tidak bisa dikembalikan.');
            }

            $this->inventoryService->reversePick([
                'item_id'            => $item->item_id,
                'location_id'        => $picklist->location_id,
                'bin_id'             => $item->bin_id,
                'qty'                => (int) $item->qty_picked,
                'transaction_number' => $picklist->picklist_no . '-HAPUS',
                'created_by'         => $userId ?: 'system',
            ]);

            $reversed = true;
        }

        PicklistItem::where('picklist_id', $picklist->id)
            ->where('order_id', $orderId)
            ->delete();

        $order = Order::find($orderId);

        if ($order && $order->status === 'picked') {
            $order->update(['status' => 'reserved']);
        }

        return $reversed;
    }
}
