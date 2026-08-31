<?php

namespace Modules\Inventory\Services;

use App\Models\User;
use App\Support\WarehouseAccess;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\StockReplenishmentRequest;
use Modules\Inventory\Models\StockReplenishmentRequestItem;
use Modules\Inventory\Repositories\StockReplenishmentRepository;
use Modules\Notification\Services\NotificationDispatcher;
use Modules\Warehouse\Models\Location;

class StockReplenishmentService
{
    public const NOTIF_PERMISSION = 'view-permintaan-restock';

    public function __construct(
        private InventoryService $inventoryService,
        private StockReplenishmentRepository $repository,
        private NotificationDispatcher $notifications,
    ) {}

    private function requestLink(string $id): string
    {
        return "/dashboard/permintaan-restock/{$id}";
    }

    public function list(?string $status, int $perPage = 10): LengthAwarePaginator
    {
        return $this->repository->paginate($status, $perPage);
    }

    public function pendingCount(): int
    {
        return $this->repository->pendingCount();
    }

    public function findDetail(string $id): ?StockReplenishmentRequest
    {
        return $this->repository->findDetail($id);
    }

    public function findDetailOrFail(string $id): StockReplenishmentRequest
    {
        return $this->repository->findDetailOrFail($id);
    }

    public function queueFromMonitor(array $payload): array
    {
        $fromId = $payload['from_location_id']
            ?? Location::getMainWarehouseId();
        $toId = $payload['to_location_id']
            ?? Location::getSmallWarehouseId();
        $itemIds = array_values(array_unique(array_filter($payload['item_ids'] ?? [])));

        if (! $fromId || ! $toId) {
            throw new \RuntimeException('Gudang Pusat / Gudang Kecil belum di-seed.');
        }
        if ($itemIds === []) {
            throw new \RuntimeException('Pilih minimal satu produk untuk dimasukkan ke permintaan restock.');
        }

        WarehouseAccess::assert($fromId);
        WarehouseAccess::assert($toId);

        $result = DB::transaction(function () use ($fromId, $toId, $itemIds): array {
            $this->lockRoute($fromId, $toId);
            $shortages = $this->repository
                ->shortagesForLocation($toId, $itemIds)
                ->filter(fn ($row): bool => (int) $row->on_hand <= 0);

            if ($shortages->isEmpty()) {
                $request = $this->repository
                    ->pendingForRouteForUpdate($fromId, $toId)
                    ->first();

                return [
                    'request' => $request?->fresh(['items', 'fromLocation', 'toLocation', 'transferOut']),
                    'queued' => [],
                    'skipped' => $itemIds,
                ];
            }

            [$request] = $this->getOrCreatePendingBatch(
                $fromId,
                $toId,
                StockReplenishmentRequest::SOURCE_MONITOR,
                Auth::id() ?: null,
                'Ditambahkan dari Monitor Stok: Dipesan namun habis.',
            );

            $queued = [];
            foreach ($shortages as $shortage) {
                $this->upsertShortageItem($request, $shortage);
                $queued[] = (string) $shortage->item_id;
            }

            $skipped = array_values(array_diff($itemIds, $queued));
            $request->update(['last_reconciled_at' => now()]);

            return [
                'request' => $request->fresh(['items', 'fromLocation', 'toLocation', 'transferOut']),
                'queued' => $queued,
                'skipped' => $skipped,
            ];
        });

        if ($result['request']) {
            $this->notifyQueueChanged($result['request'], 'stock_replenishment_request');
        }

        return $result;
    }

    public function accept(string $id, ?string $assigneeUserId = null, ?string $note = null): StockReplenishmentRequest
    {
        $request = DB::transaction(function () use ($id, $assigneeUserId, $note) {
            $request = $this->repository->findWithItems($id);

            if ($request->status !== StockReplenishmentRequest::STATUS_PENDING) {
                throw new \RuntimeException('Permintaan sudah tidak dalam status pending.');
            }

            $actorName = Auth::user()?->name;
            if (! $actorName && $request->requested_by_user_id) {
                $user = User::find($request->requested_by_user_id);
                $actorName = $user?->name;
            }
            $actorName = $actorName ?? 'System';

            $transfer = $this->inventoryService->createDraft([
                'source_location_id' => $request->from_location_id,
                'destination_location_id' => $request->to_location_id,
                'notes' => sprintf(
                    'Auto-generated dari permintaan pengisian stok #%s%s',
                    substr($request->id, 0, 8),
                    $note ? " — {$note}" : '',
                ),
                'created_by' => $actorName,
            ]);

            if ($request->items->isEmpty()) {
                throw new \RuntimeException('Permintaan tidak memiliki item yang dapat ditransfer.');
            }

            foreach ($request->items as $item) {
                $this->inventoryService->addDraftItem($transfer->id, [
                    'item_id' => $item->item_id,
                    'qty' => (int) $item->qty,
                ]);
            }

            $this->inventoryService->approveTransfer($transfer->id, [
                'approved_by' => $actorName,
                'assigned_to' => $assigneeUserId,
            ]);

            $request->update([
                'status' => StockReplenishmentRequest::STATUS_ACCEPTED,
                'accepted_at' => now(),
                'accepted_by_user_id' => Auth::id() ?: null,
                'assignee_user_id' => $assigneeUserId,
                'transfer_out_id' => $transfer->id,
                'note' => $note ?? $request->note,
            ]);

            return $request->fresh(['items', 'transferOut']);
        });

        if ($request->requested_by_user_id) {
            $this->notifications->toUser($request->requested_by_user_id, [
                'type' => 'stock_replenishment_accepted',
                'title' => 'Permintaan pengisian stok disetujui',
                'message' => 'Permintaanmu diproses menjadi transfer keluar.',
                'data' => [
                    'request_id' => $request->id,
                    'transfer_out_id' => $request->transfer_out_id,
                    'link' => $this->requestLink($request->id),
                ],
            ]);
        }

        if ($assigneeUserId) {
            $this->notifications->toUser($assigneeUserId, [
                'type' => 'task_assigned',
                'title' => 'Transfer keluar baru ditugaskan',
                'message' => 'Kamu ditugaskan menangani transfer pengisian stok.',
                'data' => [
                    'task_type' => 'inventory_transfer',
                    'transfer_out_id' => $request->transfer_out_id,
                    'link' => "/dashboard/barang-keluar/transfer/{$request->transfer_out_id}",
                ],
            ]);
        }

        return $request;
    }

    public function reject(string $id, ?string $reason = null): StockReplenishmentRequest
    {
        $request = $this->repository->findOrFail($id);

        if ($request->status !== StockReplenishmentRequest::STATUS_PENDING) {
            throw new \RuntimeException('Permintaan sudah tidak dalam status pending.');
        }

        $request->update([
            'status' => StockReplenishmentRequest::STATUS_REJECTED,
            'rejected_at' => now(),
            'rejected_by_user_id' => Auth::id() ?: null,
            'reject_reason' => $reason,
        ]);

        $fresh = $request->fresh();

        if ($fresh->requested_by_user_id) {
            $reasonSuffix = $reason ? " Alasan: {$reason}" : '';
            $this->notifications->toUser($fresh->requested_by_user_id, [
                'type' => 'stock_replenishment_rejected',
                'title' => 'Permintaan pengisian stok ditolak',
                'message' => "Permintaanmu ditolak.{$reasonSuffix}",
                'data' => [
                    'request_id' => $fresh->id,
                    'reason' => $reason,
                    'link' => $this->requestLink($fresh->id),
                ],
            ]);
        }

        return $fresh;
    }

    public function updateItem(string $id, string $itemId, array $payload): StockReplenishmentRequestItem
    {
        return DB::transaction(function () use ($id, $itemId, $payload) {
            $request = $this->lockPendingRequest($id);

            $item = $request->items()->where('id', $itemId)->firstOrFail();

            $item->update([
                'qty' => (int) ($payload['qty'] ?? $item->qty),
                'reason' => array_key_exists('reason', $payload) ? $payload['reason'] : $item->reason,
            ]);

            return $item->fresh(['variant.product']);
        });
    }

    public function removeItem(string $id, string $itemId): void
    {
        DB::transaction(function () use ($id, $itemId) {
            $request = $this->lockPendingRequest($id);

            $request->items()->where('id', $itemId)->firstOrFail()->delete();
        });
    }

    private function lockPendingRequest(string $id): StockReplenishmentRequest
    {
        $request = $this->repository->lockForUpdate($id);

        if ($request->status !== StockReplenishmentRequest::STATUS_PENDING) {
            throw new \RuntimeException('Item hanya dapat diubah selagi permintaan masih menunggu persetujuan.');
        }

        return $request;
    }

    public function markDone(string $id): StockReplenishmentRequest
    {
        $request = $this->repository->findOrFail($id);

        $request->update([
            'status' => StockReplenishmentRequest::STATUS_DONE,
            'done_at' => now(),
        ]);

        return $request->fresh();
    }

    public function autoDetect(bool $dryRun = false): array
    {
        return $this->reconcileAutoBatch($dryRun);
    }

    public function reconcileAutoBatch(bool $dryRun = false): array
    {
        $kecilId = Location::getSmallWarehouseId();
        $pusatId = Location::getMainWarehouseId();

        if (! $kecilId || ! $pusatId) {
            return [
                'shortages' => [],
                'request' => null,
                'skipped' => true,
                'reason' => 'Gudang Kecil / Gudang Pusat belum di-seed',
            ];
        }

        $shortages = $this->repository->shortagesForLocation($kecilId);
        $shortageRows = $shortages->values()->map(fn ($row): array => [
            'item_id' => $row->item_id,
            'sku' => $row->sku,
            'qty' => $row->shortage,
            'needed' => $row->needed,
            'available' => $row->available,
            'in_flight' => $row->in_flight,
        ])->all();

        if ($dryRun) {
            return ['shortages' => $shortageRows, 'request' => null, 'skipped' => false];
        }

        $created = false;
        $changed = false;
        $request = DB::transaction(function () use (
            $pusatId,
            $kecilId,
            $shortages,
            &$created,
            &$changed,
        ): ?StockReplenishmentRequest {
            $this->lockRoute($pusatId, $kecilId);

            $pending = $this->repository->pendingForRouteForUpdate($pusatId, $kecilId);
            if ($pending->isEmpty()) {
                return null;
            }

            [$request, $created] = $this->getOrCreatePendingBatch(
                $pusatId,
                $kecilId,
                StockReplenishmentRequest::SOURCE_MONITOR,
                null,
                null,
            );

            $requestedItemIds = $request->items()->pluck('item_id')->all();
            foreach ($shortages->only($requestedItemIds) as $shortage) {
                $changed = $this->upsertShortageItem($request, $shortage) || $changed;
            }

            $validItemIds = $shortages->keys()
                ->intersect($requestedItemIds)
                ->values()
                ->all();
            $removed = $request->items()
                ->whereNotIn('item_id', $validItemIds)
                ->delete();
            $changed = $changed || $removed > 0;

            if ($request->items()->doesntExist()) {
                $request->update([
                    'status' => StockReplenishmentRequest::STATUS_CANCELLED,
                    'cancelled_at' => now(),
                    'cancel_reason' => 'Semua item sudah tidak membutuhkan restock.',
                    'batch_key' => null,
                    'last_reconciled_at' => now(),
                ]);

                return $request->fresh(['items', 'fromLocation', 'toLocation', 'transferOut']);
            }

            $request->update([
                'last_reconciled_at' => now(),
            ]);

            return $request->fresh(['items', 'fromLocation', 'toLocation', 'transferOut']);
        });

        if ($request && ($created || $changed)) {
            $this->notifyQueueChanged($request, 'stock_replenishment_auto_detected');
        }

        return [
            'shortages' => $shortageRows,
            'request' => $request,
            'skipped' => false,
        ];
    }

    private function lockRoute(string $fromLocationId, string $toLocationId): void
    {
        $key = "stock-replenishment:{$fromLocationId}:{$toLocationId}";

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', [$key]);
        }
    }

    private function getOrCreatePendingBatch(
        string $fromLocationId,
        string $toLocationId,
        string $source,
        ?string $requestedByUserId,
        ?string $note,
    ): array {
        $pending = $this->repository->pendingForRouteForUpdate($fromLocationId, $toLocationId);
        $request = $pending->first();

        if ($request) {
            foreach ($pending->slice(1) as $duplicate) {
                foreach ($duplicate->items()->get() as $duplicateItem) {
                    $sameItem = $request->items()
                        ->where('item_id', $duplicateItem->item_id)
                        ->first();
                    if ($sameItem) {
                        $sameItem->qty = max((int) $sameItem->qty, (int) $duplicateItem->qty);
                        $sameItem->save();
                        $duplicateItem->delete();
                    } else {
                        $duplicateItem->update(['request_id' => $request->id]);
                    }
                }
                $duplicate->update([
                    'status' => StockReplenishmentRequest::STATUS_CANCELLED,
                    'cancelled_at' => now(),
                    'cancel_reason' => 'Digabung ke batch pending yang sudah ada.',
                    'batch_key' => null,
                ]);
            }

            $mergedSource = $this->mergedSource($request->source, $source);
            $request->update([
                'source' => $mergedSource,
                'batch_key' => $this->routeBatchKey($fromLocationId, $toLocationId),
            ]);

            return [$request->fresh(['items']), false];
        }

        $request = $this->repository->create([
            'requested_by_user_id' => $requestedByUserId,
            'from_location_id' => $fromLocationId,
            'to_location_id' => $toLocationId,
            'status' => StockReplenishmentRequest::STATUS_PENDING,
            'source' => $source,
            'batch_key' => $this->routeBatchKey($fromLocationId, $toLocationId),
            'requested_at' => now(),
            'note' => $note,
        ]);

        return [$request, true];
    }

    private function routeBatchKey(string $fromLocationId, string $toLocationId): string
    {
        return "route:{$fromLocationId}:{$toLocationId}";
    }

    private function mergedSource(?string $current, string $incoming): string
    {
        if (! $current || $current === $incoming) {
            return $incoming;
        }

        return StockReplenishmentRequest::SOURCE_MIXED;
    }

    private function upsertShortageItem(
        StockReplenishmentRequest $request,
        object $shortage,
    ): bool {
        $item = $request->items()
            ->where('item_id', $shortage->item_id)
            ->lockForUpdate()
            ->first();

        $values = [
            'sku' => $shortage->sku,
            'qty' => (int) $shortage->shortage,
            'demand_qty' => (int) $shortage->needed,
            'available_qty' => (int) $shortage->available,
            'in_flight_qty' => (int) $shortage->in_flight,
            'suggested_qty' => (int) $shortage->shortage,
            'reason' => sprintf(
                'Kebutuhan %d, tersedia %d, sedang dikirim %d.',
                $shortage->needed,
                $shortage->available,
                $shortage->in_flight,
            ),
        ];

        if (! $item) {
            $request->items()->create(array_merge([
                'item_id' => $shortage->item_id,
            ], $values));

            return true;
        }

        $changed = collect($values)->contains(
            fn ($value, $key): bool => (string) $item->{$key} !== (string) $value,
        );
        $item->update($values);

        return $changed;
    }

    private function notifyQueueChanged(
        StockReplenishmentRequest $request,
        string $notificationType,
    ): void {
        $request->loadMissing('items');
        $skuCount = $request->items->count();
        if ($skuCount === 0) {
            return;
        }

        $this->notifications->toPermission(self::NOTIF_PERMISSION, [
            'type' => $notificationType,
            'title' => 'Antrian restock diperbarui',
            'message' => "{$skuCount} SKU menunggu review permintaan restock.",
            'data' => [
                'request_id' => $request->id,
                'link' => $this->requestLink($request->id),
            ],
        ], excludeUserIds: array_filter([$request->requested_by_user_id]));
    }
}
