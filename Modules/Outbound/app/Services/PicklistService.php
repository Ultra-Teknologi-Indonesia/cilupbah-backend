<?php

namespace Modules\Outbound\Services;

use App\Enums\AssignmentActionEnum;
use App\Enums\UnassignReasonEnum;
use App\Exceptions\UserFacingException;
use App\Models\AssignmentHistory;
use App\Models\User;
use App\Support\WarehouseAccess;
use App\Traits\EnforcesAssignmentChannel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Repositories\InventoryMovementRepository;
use Modules\Inventory\Services\InventoryService;
use Modules\Notification\Events\TaskAssigned;
use Modules\Outbound\Events\PicklistItemFailed;
use Modules\Outbound\Exceptions\OutboundValidationException;
use Modules\Outbound\Jobs\ProcessPicklistCompleteJob;
use Modules\Outbound\Models\Packlist;
use Modules\Outbound\Models\Picklist;
use Modules\Outbound\Models\PicklistItem;
use Modules\Outbound\Models\PicklistItemAllocation;
use Modules\Outbound\Repositories\PicklistRepository;
use Modules\Product\Repositories\ProductRepository;
use Modules\Sales\Models\SalesOrder as Order;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;

class PicklistService
{
    use EnforcesAssignmentChannel;

    public function __construct(
        protected PicklistRepository $picklistRepository,
        protected InventoryMovementRepository $movementRepository,
        protected InventoryService $inventoryService,
        protected ProductRepository $productRepository,
    ) {}

    protected function assignedToColumn(Model $doc): string
    {
        return 'picker_id';
    }

    protected function unlockedOnceColumn(Model $doc): string
    {
        return 'completed_at';
    }

    public function unassign(
        string $picklistId,
        string $actorId,
        UnassignReasonEnum $reason,
        ?string $reasonNote = null,
        ?string $newPickerId = null,
    ): Picklist {
        return DB::transaction(function () use ($picklistId, $actorId, $reason, $reasonNote, $newPickerId) {
            $picklist = Picklist::lockForUpdate()->findOrFail($picklistId);
            $previousPicker = $picklist->picker_id;
            $isSelf = $previousPicker !== null && (string) $previousPicker === $actorId;
            $action = $isSelf ? AssignmentActionEnum::SELF_UNASSIGN : AssignmentActionEnum::UNASSIGN;

            $picklist->forceFill([
                'picker_id' => $newPickerId,
                'assigned_by' => $newPickerId ? $actorId : null,
                'assigned_at' => $newPickerId ? now() : null,
                'updated_version_at' => now(),
            ])->save();

            AssignmentHistory::create([
                'subject_type' => Picklist::class,
                'subject_id' => $picklist->id,
                'from_user_id' => $previousPicker,
                'to_user_id' => $newPickerId,
                'actor_id' => $actorId,
                'action' => $action->value,
                'channel' => $this->currentChannel()->value,
                'reason_code' => $reason->value,
                'reason_note' => $reasonNote,
            ]);

            return $picklist->fresh();
        });
    }

    public function resetAssignmentDestructive(
        string $picklistId,
        string $actorId,
        string $reasonNote,
        ?string $newPickerId = null,
    ): Picklist {
        return DB::transaction(function () use ($picklistId, $actorId, $reasonNote, $newPickerId) {
            $picklist = Picklist::lockForUpdate()->with('items')->findOrFail($picklistId);
            $previousPicker = $picklist->picker_id;

            foreach ($picklist->items as $item) {
                if ((int) $item->picked_qty > 0) {
                    PicklistItemAllocation::where('picklist_item_id', $item->id)->delete();
                    $item->forceFill(['picked_qty' => 0])->save();
                }
            }

            $picklist->refresh()->forceFill([
                'picker_id' => $newPickerId,
                'assigned_by' => $newPickerId ? $actorId : null,
                'assigned_at' => $newPickerId ? now() : null,
                'status' => Picklist::STATUS_DRAFT,
                'started_at' => null,
                'completed_at' => null,
                'updated_version_at' => now(),
            ])->save();

            AssignmentHistory::create([
                'subject_type' => Picklist::class,
                'subject_id' => $picklist->id,
                'from_user_id' => $previousPicker,
                'to_user_id' => $newPickerId,
                'actor_id' => $actorId,
                'action' => AssignmentActionEnum::FORCE_RESET->value,
                'channel' => $this->currentChannel()->value,
                'reason_code' => UnassignReasonEnum::FORCE_RESET->value,
                'reason_note' => $reasonNote,
            ]);

            return $picklist->fresh();
        });
    }

    public function getAllPaginated(int $limit = 10)
    {
        return $this->picklistRepository->getAllPaginated($limit);
    }

    public function getStatusCounts(?string $locationId = null, ?string $pickerId = null): array
    {
        $query = Picklist::query();
        if ($locationId !== null && $locationId !== '') {
            $query->where('location_id', $locationId);
        }
        if ($pickerId !== null && $pickerId !== '') {
            $query->where('picker_id', $pickerId);
        }

        WarehouseAccess::apply($query, 'location_id');

        $rows = $query
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->toArray();

        $statuses = [
            Picklist::STATUS_DRAFT,
            Picklist::STATUS_IN_PROGRESS,
            Picklist::STATUS_COMPLETED,
            Picklist::STATUS_FAILED,
            Picklist::STATUS_CANCELLED,
        ];

        $counts = [];
        foreach ($statuses as $s) {
            $counts[$s] = (int) ($rows[$s] ?? 0);
        }
        foreach ($rows as $s => $n) {
            if (! array_key_exists($s, $counts)) {
                $counts[$s] = (int) $n;
            }
        }

        return $counts;
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
        WarehouseAccess::assert($data['location_id'] ?? null);

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

            $this->assertChannelLocation($orders, (string) $data['location_id']);

            foreach ($orders as $order) {
                foreach ($order->items as $orderItem) {
                    $components = $this->productRepository->bundleComponentsForVariant($orderItem->item_id);

                    if ($components !== null) {
                        foreach ($components as $comp) {
                            $this->picklistRepository->createItem([
                                'picklist_id' => $picklist->id,
                                'order_id' => $order->id,
                                'order_item_id' => $orderItem->id,
                                'item_id' => $comp['variant_id'],
                                'sku' => $comp['sku'] ?? $orderItem->sku,
                                'bin_id' => null,
                                'qty_ordered' => $orderItem->qty_in_base * $comp['qty'],
                                'qty_picked' => 0,
                            ]);
                        }
                    } else {
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
            }

            $picklist = $this->picklistRepository->findById($picklist->id);

            if (! empty($data['picker_id'])) {
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

        if (! $picklist) {
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

        if (! $picklist) {
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

    public function pickItem(string $picklistId, string $itemId, array $data)
    {
        DB::transaction(function () use ($picklistId, $itemId, $data) {
            $picklist = $this->picklistRepository->findById($picklistId);

            if (! $picklist) {
                throw new OutboundValidationException('Picklist tidak ditemukan.');
            }

            $item = PicklistItem::where('picklist_id', $picklistId)
                ->where('id', $itemId)
                ->lockForUpdate()
                ->first();

            if (! $item) {
                throw new OutboundValidationException('Item picklist tidak ditemukan.');
            }

            if ($item->item_status === PicklistItem::STATUS_PROCESSED_EXTERNALLY) {
                throw new OutboundValidationException(
                    "SKU {$item->sku} untuk pesanan ini sudah terkirim melalui channel dan tidak perlu di-scan ulang."
                );
            }

            if (! in_array($picklist->status, [Picklist::STATUS_DRAFT, Picklist::STATUS_IN_PROGRESS], true)) {
                throw new OutboundValidationException("Picklist tidak bisa di-pick (status saat ini: {$picklist->status}).");
            }

            $pickOrder = $item->order_id
                ? Order::query()->whereKey($item->order_id)->lockForUpdate()->first()
                : null;

            if ($pickOrder && in_array($pickOrder->status, ['shipped', 'completed', 'delivered'], true)) {
                $current = (int) $item->qty_picked;
                $target = array_key_exists('qty_delta', $data) && $data['qty_delta'] !== null
                    ? $current + (int) $data['qty_delta']
                    : (int) $data['qty_picked'];

                $this->picklistRepository->updateItem($itemId, [
                    'qty_picked' => $target,
                    'item_status' => PicklistItem::STATUS_PROCESSED_EXTERNALLY,
                    'bin_id' => $this->resolveBin($picklist, $data['bin_code'])->id ?? null,
                ]);
                return [
                    'already_shipped' => true,
                    'order_no' => $pickOrder->salesorder_no,
                ];
            }

            if ($pickOrder && $pickOrder->is_canceled) {
                throw new OutboundValidationException(
                    "Pesanan {$pickOrder->salesorder_no} sudah DIBATALKAN — jangan dipick, pisahkan barangnya."
                );
            }

            $this->assertChannelLocation(collect([$pickOrder])->filter(), (string) $picklist->location_id);

            $current = (int) $item->qty_picked;
            $ordered = (int) $item->qty_ordered;

            $target = array_key_exists('qty_delta', $data) && $data['qty_delta'] !== null
                ? $current + (int) $data['qty_delta']
                : (int) $data['qty_picked'];

            if ($target > $ordered) {
                throw $this->itemAlreadyFullException($item, $current, $ordered);
            }

            if ($target < 0) {
                throw new OutboundValidationException('Qty hasil koreksi tidak boleh negatif.');
            }

            $bin = $this->resolveBin($picklist, $data['bin_code']);
            $delta = $target - $current;
            $userId = (string) (Auth::id() ?? $picklist->picker_id ?? 'system');

            if ($delta > 0) {
                $this->assertInventoryForPick($item, (string) $picklist->location_id, $bin);
                $this->commitPickAllocation($picklist, $item, $bin, $delta, $userId);

                $this->picklistRepository->updateItem($itemId, [
                    'qty_picked' => $target,
                    'bin_id' => $bin->id,
                ]);
            } elseif ($delta < 0) {
                $this->inventoryService->reversePick([
                    'item_id' => $item->item_id,
                    'location_id' => $picklist->location_id,
                    'bin_id' => $item->bin_id ?? $bin->id,
                    'qty' => -$delta,
                    'transaction_number' => $picklist->picklist_no.'-KOREKSI',
                    'created_by' => $userId,
                ]);

                $this->picklistRepository->updateItem($itemId, [
                    'qty_picked' => $target,
                ]);

                $this->revertPicklistCompletion($picklistId);
            } else {

                $this->picklistRepository->updateItem($itemId, [
                    'bin_id' => $bin->id,
                ]);
            }

            if ($item->order_id) {
                app(OrderReleaseService::class)
                    ->releaseIfComplete($picklist, (string) $item->order_id);
            }
        });

        $this->autoCompleteIfResolved($picklistId);
    }

    private function autoCompleteIfResolved(string $picklistId): void
    {
        DB::transaction(function () use ($picklistId): void {
            $picklist = Picklist::query()
                ->with('items')
                ->lockForUpdate()
                ->find($picklistId);

            if (! $picklist || ! in_array($picklist->status, [Picklist::STATUS_DRAFT, Picklist::STATUS_IN_PROGRESS], true)) {
                return;
            }

            if ($picklist->items->isEmpty() || ! $picklist->items->every(function (PicklistItem $item): bool {
                return $item->isResolved();
            })) {
                return;
            }

            $this->picklistRepository->update($picklistId, [
                'status' => Picklist::STATUS_COMPLETED,
                'completed_at' => now(),
            ]);

            ProcessPicklistCompleteJob::dispatch($picklistId);
        });
    }

    private function itemAlreadyFullException(PicklistItem $item, int $current, int $ordered): UserFacingException
    {
        $lastAllocation = PicklistItemAllocation::where('picklist_item_id', $item->id)
            ->orderByDesc('picked_at')
            ->first();

        $pickerName = $lastAllocation?->picked_by
            ? User::find($lastAllocation->picked_by)?->name
            : null;

        $message = $pickerName
            ? "{$item->sku} sudah diambil {$current} dari {$ordered} oleh {$pickerName}."
            : "{$item->sku} sudah diambil {$current} dari {$ordered}.";

        return new UserFacingException(
            title: 'Barang sudah lengkap',
            message: $message,
            status: 409,
            errors: [
                'code' => 'ITEM_ALREADY_FULL',
                'item_id' => (string) $item->id,
                'qty_picked' => $current,
                'qty_ordered' => $ordered,
            ],
        );
    }

    private function assertChannelLocation(iterable $orders, string $locationId): void
    {
        $hasChannelOrder = collect($orders)->contains(function ($order): bool {
            return ! in_array(strtolower((string) ($order?->source ?? '')), ['', 'manual'], true);
        });

        if (! $hasChannelOrder) {
            return;
        }

        $officialLocationId = Location::getOfficialSmallWarehouseId();

        if ($officialLocationId !== null && $officialLocationId !== $locationId) {
            throw new OutboundValidationException(
                'Pesanan channel wajib diproses dan dipotong dari Gudang Kecil (WH-KECIL).'
            );
        }
    }

    public function unpickItem(string $picklistId, string $itemId, ?int $qty, string $userId): Picklist
    {
        return $this->unpickItems($picklistId, [
            ['item_id' => $itemId, 'qty' => $qty],
        ], $userId);
    }

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

            $lockedItems = PicklistItem::where('picklist_id', $picklistId)
                ->whereIn('id', collect($items)->pluck('item_id')->filter()->all())
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($items as $entry) {
                $itemId = $entry['item_id'] ?? null;
                $qty = $entry['qty'] ?? null;

                if (! $itemId) {
                    throw new OutboundValidationException('item_id wajib diisi.');
                }

                $item = $lockedItems[$itemId] ?? null;

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
                    'item_id' => $item->item_id,
                    'location_id' => $picklist->location_id,
                    'bin_id' => $item->bin_id,
                    'qty' => $qtyRev,
                    'transaction_number' => $picklist->picklist_no.'-KOREKSI',
                    'created_by' => (string) ($userId ?: 'system'),
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

        if (! $picklist) {
            throw new OutboundValidationException('Picklist tidak ditemukan.');
        }

        if (! in_array($picklist->status, [Picklist::STATUS_DRAFT, Picklist::STATUS_IN_PROGRESS])) {
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

        if ($candidates->isEmpty()) {
            $isBundle = $this->productRepository->bundleComponentsForVariant($item->item_id) !== null;

            $pending = (int) Inventory::where('item_id', $item->item_id)
                ->where('location_id', $picklist->location_id)
                ->pendingPlacement()
                ->sum('on_hand');

            if ($pending > 0 && ! $isBundle) {
                throw new OutboundValidationException(
                    "Stok {$item->sku} ada {$pending} tapi belum ditempatkan ke rak. Lakukan Penempatan (putaway) dulu sebelum picking."
                );
            }

            $msg = $isBundle
                ? "Stok komponen bundle {$item->sku} kosong di gudang ini. Pastikan semua komponen sudah di-receive & putaway."
                : "Stok {$item->sku} kosong di gudang ini. Butuh replenishment.";
            throw new OutboundValidationException($msg);
        }

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

    private function assertInventoryForPick(PicklistItem $item, string $locationId, LocationBin $bin): int
    {

        if ($bin->is_inbound) {
            throw new OutboundValidationException(
                "Rak {$bin->bin_final_code} adalah Bin Inbound (stok belum ditempatkan). Lakukan Penempatan dulu, lalu pick dari rak final."
            );
        }

        $inventory = Inventory::where('item_id', $item->item_id)
            ->where('location_id', $locationId)
            ->where('bin_id', $bin->id)
            ->first();

        if (! $inventory) {
            throw new OutboundValidationException("SKU ini tidak ditemukan di rak {$bin->bin_final_code}. Silahkan pilih rak lain.");
        }

        $available = (int) $inventory->on_hand;

        if (! config('inventory.allow_negative_stock', true) && $available <= 0) {
            throw new OutboundValidationException("Stok tidak cukup di rak {$bin->bin_final_code}. Tersedia: {$available}. Silahkan pilih rak lain.");
        }

        return $available;
    }

    private function suggestBinsForItem(Picklist $picklist, PicklistItem $item): Collection
    {

        $rows = Inventory::where('item_id', $item->item_id)
            ->where('location_id', $picklist->location_id)
            ->placed()
            ->where('on_hand', '>', 0)
            ->with(['bin:id,bin_final_code'])
            ->orderByBinMovement('lifo')
            ->get();

        return $rows
            ->filter(fn ($inv) => $inv->bin !== null)
            ->map(fn ($inv) => [
                'bin_id' => $inv->bin_id,
                'bin_code' => $inv->bin->bin_final_code,
                'on_hand' => (int) $inv->on_hand,
            ]);
    }

    private function pickDefaultCandidate(Collection $candidates, int $remaining, ?string $hintActiveBinCode): array
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

        if (! $item) {
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

        if (! $bin) {
            throw new OutboundValidationException("Rak dengan kode '{$binCode}' tidak ditemukan.");
        }

        return $bin;
    }

    public function complete(string $id): Picklist
    {
        $picklist = $this->picklistRepository->findById($id);

        if (! $picklist) {
            throw new \Exception('Picklist tidak ditemukan.');
        }

        if (! in_array($picklist->status, [Picklist::STATUS_DRAFT, Picklist::STATUS_IN_PROGRESS])) {
            throw new OutboundValidationException("Hanya picklist DRAFT/IN_PROGRESS yang bisa di-complete (saat ini: {$picklist->status}).");
        }

        $unfinished = $picklist->items->filter(function ($item) {
            $picked = (int) $item->qty_picked;
            $ordered = (int) $item->qty_ordered;
            if ($picked >= $ordered) {
                return false;
            }
            $status = $item->item_status;

            return ! in_array($status, PicklistItem::resolvedStatuses(), true);
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
        if (! in_array($reasonCode, $allowed, true)) {
            throw new OutboundValidationException("Alasan gagal tidak valid: {$reasonCode}.");
        }

        $picklist = DB::transaction(function () use ($picklistId, $itemId, $reasonCode, $reasonNote, $userId) {
            $picklist = $this->picklistRepository->findById($picklistId);

            if (! $picklist) {
                throw new OutboundValidationException('Picklist tidak ditemukan.');
            }

            if (! in_array($picklist->status, [Picklist::STATUS_DRAFT, Picklist::STATUS_IN_PROGRESS], true)) {
                throw new OutboundValidationException("Item hanya bisa di-fail saat picklist DRAFT/IN_PROGRESS (saat ini: {$picklist->status}).");
            }

            $item = PicklistItem::where('picklist_id', $picklistId)
                ->where('id', $itemId)
                ->lockForUpdate()
                ->first();

            if (! $item) {
                throw new OutboundValidationException('Item picklist tidak ditemukan.');
            }

            if ((int) $item->qty_picked >= (int) $item->qty_ordered) {
                throw new OutboundValidationException('Item sudah selesai di-pick. Batalkan pick dulu (unpick) sebelum tandai gagal.');
            }

            $itemStatus = in_array($reasonCode, [PicklistItem::REASON_DAMAGED, PicklistItem::REASON_REJECTED], true)
                ? PicklistItem::STATUS_REJECTED
                : PicklistItem::STATUS_SHORT;

            $failedQty = (int) $item->qty_ordered - (int) $item->qty_picked;

            $this->picklistRepository->updateItem($itemId, [
                'item_status' => $itemStatus,
                'fail_reason_code' => $reasonCode,
                'fail_reason_note' => $reasonNote,
                'failed_qty' => $failedQty,
                'failed_at' => now(),
                'failed_by' => $userId ?: null,
            ]);

            PicklistItemFailed::dispatch($picklistId, $itemId, $reasonCode, $failedQty, (string) ($userId ?: 'system'));

            return $this->picklistRepository->findById($picklistId);
        });

        $this->autoCompleteIfResolved($picklistId);

        return $this->picklistRepository->findById($picklistId) ?? $picklist;
    }

    public function unfailPickItem(string $picklistId, string $itemId, string $userId): Picklist
    {
        return DB::transaction(function () use ($picklistId, $itemId) {
            $picklist = $this->picklistRepository->findById($picklistId);

            if (! $picklist) {
                throw new OutboundValidationException('Picklist tidak ditemukan.');
            }

            if (! in_array($picklist->status, [Picklist::STATUS_DRAFT, Picklist::STATUS_IN_PROGRESS], true)) {
                throw new OutboundValidationException("Undo fail hanya bisa saat picklist DRAFT/IN_PROGRESS (saat ini: {$picklist->status}).");
            }

            $item = PicklistItem::where('picklist_id', $picklistId)
                ->where('id', $itemId)
                ->lockForUpdate()
                ->first();

            if (! $item) {
                throw new OutboundValidationException('Item picklist tidak ditemukan.');
            }

            $this->picklistRepository->updateItem($itemId, [
                'item_status' => null,
                'fail_reason_code' => null,
                'fail_reason_note' => null,
                'failed_qty' => null,
                'failed_at' => null,
                'failed_by' => null,
            ]);

            return $this->picklistRepository->findById($picklistId);
        });
    }

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

        if (! $inventory) {
            throw new OutboundValidationException("SKU ini tidak ditemukan di rak {$bin->bin_final_code}. Silahkan pilih rak lain.");
        }

        if ($inventory->on_hand < $qty) {
            throw new OutboundValidationException("Stok tidak cukup di rak {$bin->bin_final_code}. Tersedia: {$inventory->on_hand}, dibutuhkan: {$qty}. Silahkan pilih rak lain.");
        }

        $inventory->on_hand -= $qty;
        $inventory->recalculateAvailable();
        $inventory->save();

        $movement = $this->movementRepository->create([
            'item_id' => $item->item_id,
            'location_id' => $picklist->location_id,
            'bin_id' => $bin->id,
            'transaction_number' => $picklist->picklist_no,
            'source' => 'PICKING',
            'qty' => -$qty,
            'balance' => $inventory->on_hand,
            'transaction_date' => now(),
            'created_by' => $userId ?: 'system',
        ]);

        PicklistItemAllocation::create([
            'picklist_item_id' => $item->id,
            'bin_id' => $bin->id,
            'qty' => $qty,
            'picked_at' => now(),
            'picked_by' => $userId ?: null,
            'movement_id' => is_object($movement) ? ($movement->id ?? null) : null,
        ]);
    }

    public function failPick(string $id, ?string $reason = null): Picklist
    {
        $picklist = $this->picklistRepository->findById($id);

        if (! $picklist) {
            throw new \Exception('Picklist tidak ditemukan.');
        }

        if (! in_array($picklist->status, [Picklist::STATUS_DRAFT, Picklist::STATUS_IN_PROGRESS])) {
            throw new \Exception("Hanya picklist DRAFT/IN_PROGRESS yang bisa di-fail (saat ini: {$picklist->status}).");
        }

        $this->picklistRepository->update($id, [
            'status' => Picklist::STATUS_FAILED,
            'notes' => $reason ? ($picklist->notes ? $picklist->notes.' | FAILED: '.$reason : 'FAILED: '.$reason) : $picklist->notes,
        ]);

        return $this->picklistRepository->findById($id);
    }

    public function cancel(string $id): Picklist
    {
        $picklist = $this->picklistRepository->findById($id);

        if (! $picklist) {
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

        if (! $picklist) {
            throw new \Exception('Picklist tidak ditemukan.');
        }

        if ($picklist->status !== Picklist::STATUS_DRAFT) {
            throw new \Exception("Hanya picklist DRAFT yang bisa dihapus (saat ini: {$picklist->status}).");
        }

        return $this->picklistRepository->delete($id);
    }

    public function failPickOrder(string $picklistId, string $orderId, string $userId, string $reason): bool
    {
        return DB::transaction(function () use ($picklistId, $orderId, $userId, $reason) {
            $picklist = $this->picklistRepository->findById($picklistId);

            if (! $picklist) {
                throw new OutboundValidationException('Picklist tidak ditemukan.');
            }

            $this->assertOrderNotProgressedBeyondPicking($orderId);
            $reversed = $this->reverseAndDetachOrderFromPicklist($picklist, $orderId, $userId);

            $order = Order::find($orderId);
            if ($order) {

                $order->update([
                    'pick_failed_at' => now(),
                    'pick_failed_by' => $userId,
                    'pick_fail_reason' => $reason,
                    'handed_to_warehouse_at' => null,
                ]);
            }

            if (! PicklistItem::where('picklist_id', $picklistId)->exists()) {
                $this->picklistRepository->delete($picklistId);
            }

            return $reversed;
        });
    }

    public function revert(string $picklistId, string $userId): void
    {
        DB::transaction(function () use ($picklistId, $userId) {
            $picklist = $this->picklistRepository->findById($picklistId);

            if (! $picklist) {
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

        if (! $order) {
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
                'item_id' => $item->item_id,
                'location_id' => $picklist->location_id,
                'bin_id' => $item->bin_id,
                'qty' => (int) $item->qty_picked,
                'transaction_number' => $picklist->picklist_no.'-HAPUS',
                'created_by' => $userId ?: 'system',
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

    public function attachRecommendedBins($picklist): void
    {
        $items = $picklist->items ?? collect();
        if ($items->isEmpty()) {
            return;
        }

        $locationId = $picklist->location_id;
        $itemIds = $items->pluck('item_id')->filter()->unique()->values()->all();

        $stocks = $this->picklistRepository->recommendedBinStocks($itemIds, $locationId);

        $byItem = $stocks->groupBy('item_id');

        foreach ($items as $item) {
            $top = $byItem->get($item->item_id)?->first();
            $item->recommended_bin_code = optional($top?->bin)->bin_final_code;
        }
    }

    public function getForBulkPdf(array $orderIds): Collection
    {
        $picklists = $this->picklistRepository->getForBulkPdf($orderIds);

        foreach ($picklists as $picklist) {
            $this->attachRecommendedBins($picklist);
        }

        return $picklists;
    }
}
