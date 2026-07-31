<?php

namespace Modules\Inventory\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Inventory\Models\StockReplenishmentRequest;
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
    ) {
    }

    private function requestLink(string $id): string
    {
        return "/dashboard/permintaan-restock/{$id}";
    }

    public function list(?string $status, int $perPage = 10): \Illuminate\Pagination\LengthAwarePaginator
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

    public function create(array $payload): StockReplenishmentRequest
    {
        $request = DB::transaction(function () use ($payload) {
            $fromId = $payload['from_location_id']
                ?? $this->repository->resolveLocationId(Location::SYSTEM_PUSAT_CODE);
            $toId = $payload['to_location_id']
                ?? $this->repository->resolveLocationId(Location::SYSTEM_KECIL_CODE);

            if (! $fromId || ! $toId) {
                throw new \RuntimeException('Gudang Pusat / Gudang Kecil belum di-seed.');
            }

            $req = $this->repository->create([
                'requested_by_user_id' => $payload['requested_by_user_id'] ?? (Auth::id() ?: null),
                'from_location_id'     => $fromId,
                'to_location_id'       => $toId,
                'status'               => StockReplenishmentRequest::STATUS_PENDING,
                'requested_at'         => now(),
                'note'                 => $payload['note'] ?? null,
            ]);

            foreach (($payload['items'] ?? []) as $item) {
                $this->repository->createItem([
                    'request_id' => $req->id,
                    'item_id'    => $item['item_id'],
                    'sku'        => $item['sku'],
                    'qty'        => (int) $item['qty'],
                    'reason'     => $item['reason'] ?? null,
                ]);
            }

            return $req->fresh(['items']);
        });

        $skuCount = $request->items->count();
        $this->notifications->toPermission(self::NOTIF_PERMISSION, [
            'type' => 'stock_replenishment_request',
            'title' => 'Permintaan pengisian stok baru',
            'message' => "{$skuCount} SKU perlu diisi ke gudang kecil.",
            'data' => [
                'request_id' => $request->id,
                'link' => $this->requestLink($request->id),
            ],
        ], excludeUserIds: array_filter([$request->requested_by_user_id]));

        return $request;
    }

    public function accept(string $id, ?string $assigneeUserId = null, ?string $note = null): StockReplenishmentRequest
    {
        $request = DB::transaction(function () use ($id, $assigneeUserId, $note) {
            $request = $this->repository->findWithItems($id);

            if ($request->status !== StockReplenishmentRequest::STATUS_PENDING) {
                throw new \RuntimeException('Permintaan sudah tidak dalam status pending.');
            }

            $actorName = Auth::user()?->name;
            if (!$actorName && $request->requested_by_user_id) {
                $user = \App\Models\User::find($request->requested_by_user_id);
                $actorName = $user?->name;
            }
            $actorName = $actorName ?? 'System';

            $transfer = $this->inventoryService->createDraft([
                'source_location_id'      => $request->from_location_id,
                'destination_location_id' => $request->to_location_id,
                'notes'                   => sprintf(
                    'Auto-generated dari permintaan pengisian stok #%s%s',
                    substr($request->id, 0, 8),
                    $note ? " — {$note}" : '',
                ),
                'created_by'              => $actorName,
            ]);

            foreach ($request->items as $item) {
                try {
                    $this->inventoryService->addDraftItem($transfer->id, [
                        'item_id' => $item->item_id,
                        'qty'     => (int) $item->qty,
                    ]);
                } catch (\Throwable $e) {
                    Log::warning('Gagal menambah item ke Transfer Keluar DRAFT saat accept replenishment', [
                        'request_id'  => $request->id,
                        'transfer_id' => $transfer->id,
                        'item_id'     => $item->item_id,
                        'qty'         => $item->qty,
                        'error'       => $e->getMessage(),
                    ]);
                }
            }

            $this->inventoryService->approveTransfer($transfer->id, [
                'approved_by' => $actorId ?? 'system',
                'assigned_to' => $assigneeUserId,
            ]);

            $request->update([
                'status'              => StockReplenishmentRequest::STATUS_ACCEPTED,
                'accepted_at'         => now(),
                'accepted_by_user_id' => Auth::id() ?: null,
                'assignee_user_id'    => $assigneeUserId,
                'transfer_out_id'     => $transfer->id,
                'note'                => $note ?? $request->note,
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
            'status'              => StockReplenishmentRequest::STATUS_REJECTED,
            'rejected_at'         => now(),
            'rejected_by_user_id' => Auth::id() ?: null,
            'reject_reason'       => $reason,
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

    public function addItem(string $id, array $payload): StockReplenishmentRequestItem
    {
        return DB::transaction(function () use ($id, $payload) {
            $request = $this->lockPendingRequest($id);

            $variant = \Modules\Product\Models\ProductVariant::find($payload['item_id']);
            $sku = $payload['sku'] ?? $variant?->sku;

            if (! $sku) {
                throw new \RuntimeException('SKU tidak ditemukan untuk item ini.');
            }

            $existing = $request->items()->where('item_id', $payload['item_id'])->first();

            if ($existing) {
                $existing->update([
                    'qty'    => $existing->qty + (int) $payload['qty'],
                    'reason' => $payload['reason'] ?? $existing->reason,
                ]);

                return $existing->fresh(['variant.product']);
            }

            $item = $request->items()->create([
                'item_id' => $payload['item_id'],
                'sku'     => $sku,
                'qty'     => (int) $payload['qty'],
                'reason'  => $payload['reason'] ?? null,
            ]);

            return $item->fresh(['variant.product']);
        });
    }

    public function updateItem(string $id, string $itemId, array $payload): StockReplenishmentRequestItem
    {
        return DB::transaction(function () use ($id, $itemId, $payload) {
            $request = $this->lockPendingRequest($id);

            $item = $request->items()->where('id', $itemId)->firstOrFail();

            $item->update([
                'qty'    => (int) ($payload['qty'] ?? $item->qty),
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
            'status'  => StockReplenishmentRequest::STATUS_DONE,
            'done_at' => now(),
        ]);

        return $request->fresh();
    }

    public function autoDetect(bool $dryRun = false): array
    {
        $kecilId = $this->repository->resolveLocationId(Location::SYSTEM_KECIL_CODE);
        $pusatId = $this->repository->resolveLocationId(Location::SYSTEM_PUSAT_CODE);

        if (! $kecilId || ! $pusatId) {
            return ['shortages' => [], 'request' => null, 'skipped' => true, 'reason' => 'Gudang Kecil / Gudang Pusat belum di-seed'];
        }

        $demand = $this->repository->demandForLocation($kecilId);

        if ($demand->isEmpty()) {
            return ['shortages' => [], 'request' => null, 'skipped' => false];
        }

        $itemIds = $demand->keys()->all();

        $availability = $this->repository->availabilityForItems($itemIds, $kecilId);

        $inFlight = $this->repository->inFlightForItems($itemIds, $kecilId);

        $shortages = [];
        foreach ($demand as $itemId => $row) {
            $needed   = (int) $row->needed;
            $onHand   = (int) ($availability[$itemId]->oh ?? 0);
            $reserved = (int) ($availability[$itemId]->rv ?? 0);
            $available = max(0, $onHand - $reserved);
            $covered  = (int) ($inFlight[$itemId]->inflight ?? 0);

            $shortage = $needed - $available - $covered;

            if ($shortage > 0) {
                $shortages[] = [
                    'item_id'    => $itemId,
                    'sku'        => $row->sku,
                    'qty'        => $shortage,
                    'needed'     => $needed,
                    'available'  => $available,
                    'in_flight'  => $covered,
                ];
            }
        }

        if (empty($shortages)) {
            return ['shortages' => [], 'request' => null, 'skipped' => false];
        }

        if ($dryRun) {
            return ['shortages' => $shortages, 'request' => null, 'skipped' => false];
        }

        $request = DB::transaction(function () use ($pusatId, $kecilId, $shortages) {
            $req = $this->repository->create([
                'requested_by_user_id' => null,
                'from_location_id'     => $pusatId,
                'to_location_id'       => $kecilId,
                'status'               => StockReplenishmentRequest::STATUS_PENDING,
                'requested_at'         => now(),
                'note'                 => 'Auto-generated oleh sistem berdasarkan kekurangan stok Gudang Kecil.',
            ]);

            foreach ($shortages as $s) {
                $this->repository->createItem([
                    'request_id' => $req->id,
                    'item_id'    => $s['item_id'],
                    'sku'        => $s['sku'],
                    'qty'        => $s['qty'],
                    'reason'     => sprintf('Kebutuhan %d, tersedia %d, in-flight %d', $s['needed'], $s['available'], $s['in_flight']),
                ]);
            }

            return $req->fresh(['items']);
        });

        $skuCount = count($shortages);
        $this->notifications->toPermission(self::NOTIF_PERMISSION, [
            'type' => 'stock_replenishment_auto_detected',
            'title' => 'Deteksi kekurangan stok otomatis',
            'message' => "Sistem mendeteksi kekurangan {$skuCount} SKU di gudang kecil.",
            'data' => [
                'request_id' => $request->id,
                'link' => $this->requestLink($request->id),
            ],
        ]);

        return ['shortages' => $shortages, 'request' => $request, 'skipped' => false];
    }
}
