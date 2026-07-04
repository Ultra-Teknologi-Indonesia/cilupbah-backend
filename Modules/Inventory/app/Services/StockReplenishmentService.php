<?php

namespace Modules\Inventory\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Inventory\Models\StockReplenishmentRequest;
use Modules\Inventory\Models\StockReplenishmentRequestItem;
use Modules\Warehouse\Models\Location;

class StockReplenishmentService
{
    public function __construct(private InventoryService $inventoryService)
    {
    }

    public function create(array $payload): StockReplenishmentRequest
    {
        return DB::transaction(function () use ($payload) {
            $fromId = $payload['from_location_id']
                ?? Location::where('location_code', Location::SYSTEM_PUSAT_CODE)->value('id');
            $toId = $payload['to_location_id']
                ?? Location::where('location_code', Location::SYSTEM_KECIL_CODE)->value('id');

            if (! $fromId || ! $toId) {
                throw new \RuntimeException('Gudang Pusat / Gudang Kecil belum di-seed.');
            }

            $request = StockReplenishmentRequest::create([
                'requested_by_user_id' => $payload['requested_by_user_id'] ?? (Auth::id() ?: null),
                'from_location_id'     => $fromId,
                'to_location_id'       => $toId,
                'status'               => StockReplenishmentRequest::STATUS_PENDING,
                'requested_at'         => now(),
                'note'                 => $payload['note'] ?? null,
            ]);

            foreach (($payload['items'] ?? []) as $item) {
                StockReplenishmentRequestItem::create([
                    'request_id' => $request->id,
                    'item_id'    => $item['item_id'],
                    'sku'        => $item['sku'],
                    'qty'        => (int) $item['qty'],
                    'reason'     => $item['reason'] ?? null,
                ]);
            }

            return $request->fresh(['items']);
        });
    }

    public function accept(string $id, ?string $assigneeUserId = null, ?string $note = null): StockReplenishmentRequest
    {
        return DB::transaction(function () use ($id, $assigneeUserId, $note) {
            $request = StockReplenishmentRequest::with('items')->findOrFail($id);

            if ($request->status !== StockReplenishmentRequest::STATUS_PENDING) {
                throw new \RuntimeException('Permintaan sudah tidak dalam status pending.');
            }

            $actorId = Auth::id() ?: $request->requested_by_user_id;

            $transfer = $this->inventoryService->createDraft([
                'source_location_id'      => $request->from_location_id,
                'destination_location_id' => $request->to_location_id,
                'notes'                   => sprintf(
                    'Auto-generated dari permintaan pengisian stok #%s%s',
                    substr($request->id, 0, 8),
                    $note ? " — {$note}" : '',
                ),
                'created_by'              => $actorId,
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
    }

    public function reject(string $id, ?string $reason = null): StockReplenishmentRequest
    {
        $request = StockReplenishmentRequest::findOrFail($id);

        if ($request->status !== StockReplenishmentRequest::STATUS_PENDING) {
            throw new \RuntimeException('Permintaan sudah tidak dalam status pending.');
        }

        $request->update([
            'status'              => StockReplenishmentRequest::STATUS_REJECTED,
            'rejected_at'         => now(),
            'rejected_by_user_id' => Auth::id() ?: null,
            'reject_reason'       => $reason,
        ]);

        return $request->fresh();
    }

    public function markDone(string $id): StockReplenishmentRequest
    {
        $request = StockReplenishmentRequest::findOrFail($id);

        $request->update([
            'status'  => StockReplenishmentRequest::STATUS_DONE,
            'done_at' => now(),
        ]);

        return $request->fresh();
    }
}
