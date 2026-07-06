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

            // Menerima permintaan = menyetujui transfer keluar: tetapkan rak asal
            // otomatis (LIFO) & reserve stok, sehingga transfer langsung berstatus
            // APPROVED dan siap dicetak/dikirim (tanpa langkah approve manual).
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

    /**
     * Deteksi otomatis kekurangan stok di Gudang Kecil untuk seluruh order
     * yang berstatus reserved. Jika ada SKU yang kekurangannya belum ter-cover
     * oleh request PENDING/ACCEPTED sebelumnya, buat satu request PENDING baru
     * yang mengelompokkan semua SKU kurang tersebut.
     *
     * @param  bool  $dryRun  jika true, tidak menulis apapun, hanya kembalikan
     *                       daftar shortage yang akan dibuat.
     * @return array  { shortages: [...], request?: StockReplenishmentRequest|null, skipped: bool }
     */
    public function autoDetect(bool $dryRun = false): array
    {
        $kecilId = DB::table('locations')
            ->where('location_code', Location::SYSTEM_KECIL_CODE)
            ->value('id');
        $pusatId = DB::table('locations')
            ->where('location_code', Location::SYSTEM_PUSAT_CODE)
            ->value('id');

        if (! $kecilId || ! $pusatId) {
            return ['shortages' => [], 'request' => null, 'skipped' => true, 'reason' => 'Gudang Kecil / Gudang Pusat belum di-seed'];
        }

        // 1. Agregasi kebutuhan pesanan reserved di Gudang Kecil per item_id
        $demand = DB::table('sales_order_items as i')
            ->join('sales_orders as o', 'o.id', '=', 'i.order_id')
            ->where('o.status', 'reserved')
            ->where('o.location_id', $kecilId)
            ->whereNotNull('i.item_id')
            ->groupBy('i.item_id', 'i.sku')
            ->select('i.item_id', 'i.sku', DB::raw('SUM(i.qty_in_base) as needed'))
            ->get()
            ->keyBy('item_id');

        if ($demand->isEmpty()) {
            return ['shortages' => [], 'request' => null, 'skipped' => false];
        }

        $itemIds = $demand->keys()->all();

        // 2. Availability di Gudang Kecil per item_id
        $availability = DB::table('inventories')
            ->whereIn('item_id', $itemIds)
            ->where('location_id', $kecilId)
            ->groupBy('item_id')
            ->select('item_id', DB::raw('COALESCE(SUM(on_hand),0) as oh'), DB::raw('COALESCE(SUM(reserved),0) as rv'))
            ->get()
            ->keyBy('item_id');

        // 3. In-flight replenishment quantities (PENDING atau ACCEPTED) per item_id
        $inFlight = DB::table('stock_replenishment_request_items as ri')
            ->join('stock_replenishment_requests as r', 'r.id', '=', 'ri.request_id')
            ->whereIn('r.status', [
                StockReplenishmentRequest::STATUS_PENDING,
                StockReplenishmentRequest::STATUS_ACCEPTED,
            ])
            ->where('r.to_location_id', $kecilId)
            ->whereIn('ri.item_id', $itemIds)
            ->groupBy('ri.item_id')
            ->select('ri.item_id', DB::raw('SUM(ri.qty) as inflight'))
            ->get()
            ->keyBy('item_id');

        // 4. Hitung shortage bersih
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

        // 5. Buat satu request PENDING agregasi
        $request = DB::transaction(function () use ($pusatId, $kecilId, $shortages) {
            $req = StockReplenishmentRequest::create([
                'requested_by_user_id' => null,
                'from_location_id'     => $pusatId,
                'to_location_id'       => $kecilId,
                'status'               => StockReplenishmentRequest::STATUS_PENDING,
                'requested_at'         => now(),
                'note'                 => 'Auto-generated oleh sistem berdasarkan kekurangan stok Gudang Kecil.',
            ]);

            foreach ($shortages as $s) {
                StockReplenishmentRequestItem::create([
                    'request_id' => $req->id,
                    'item_id'    => $s['item_id'],
                    'sku'        => $s['sku'],
                    'qty'        => $s['qty'],
                    'reason'     => sprintf('Kebutuhan %d, tersedia %d, in-flight %d', $s['needed'], $s['available'], $s['in_flight']),
                ]);
            }

            return $req->fresh(['items']);
        });

        return ['shortages' => $shortages, 'request' => $request, 'skipped' => false];
    }
}
