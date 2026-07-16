<?php

namespace Modules\Inbound\Services;

use App\Enums\AssignmentActionEnum;
use App\Enums\UnassignReasonEnum;
use App\Exceptions\AssignmentLockException;
use App\Exceptions\InboundSessionClosedException;
use App\Exceptions\MobileSessionActiveException;
use App\Exceptions\PutawayActiveException;
use App\Exceptions\UserFacingException;
use App\Models\AssignmentHistory;
use App\Traits\EnforcesAssignmentChannel;
use Illuminate\Database\Eloquent\Model;
use Modules\Inbound\Repositories\InboundRepository;
use Modules\Inbound\Models\Inbound;
use Modules\Inbound\Models\InboundAssignment;
use Modules\Inbound\Models\InboundItem;
use Modules\Inbound\Models\InboundParticipant;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Inventory\Models\Putaway;
use Modules\Inventory\Models\PutawayItem;
use Modules\Inventory\Models\PutawayPlacement;
use Modules\Inventory\Models\PutawaySource;
use Modules\Inventory\Services\InventoryService;
use Modules\Inventory\Services\PutawayService;
use Modules\Product\Models\ProductVariant;
use Modules\Warehouse\Models\LocationBin;
use Modules\Notification\Events\TaskAssigned;
use Modules\Notification\Services\NotificationDispatcher;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Models\PurchaseOrderItem;
use Modules\Warehouse\Services\LocationBinService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InboundService
{
    use EnforcesAssignmentChannel;

    private const NOTIF_PENEMPATAN = 'manage-penempatan';

    public function __construct(
        protected InboundRepository $inboundRepository,
        protected InventoryService $inventoryService,
        protected LocationBinService $binService,
        protected PutawayService $putawayService,
        protected NotificationDispatcher $notifications,
    ) {}

    protected function unlockedOnceColumn(Model $doc): string
    {
        return 'once_received_at';
    }

    /**
     * Fase 2 override: web edit ditolak selagi ada participant mobile ACTIVE.
     * Butuh once_received_at IS NOT NULL DAN tidak ada participant ACTIVE.
     */
    protected function assertWebCanMutate(Model $doc): void
    {
        $doc->refresh();

        if ($this->isCancelled($doc)) {
            return;
        }

        // Guard fase 1: butuh sekali pernah RECEIVED.
        if (! $this->isUnlockedByCompletion($doc)) {
            // Fallback: kalau belum pernah receive DAN belum ada participant → izinkan (dokumen belum tersentuh mobile).
            if (! $doc instanceof Inbound || ! $doc->hasActiveParticipant()) {
                return;
            }
        }

        // Guard fase 2: tolak kalau ada participant ACTIVE.
        if ($doc instanceof Inbound && $doc->hasActiveParticipant()) {
            $active = InboundParticipant::where('inbound_id', $doc->id)
                ->where('status', InboundParticipant::STATUS_ACTIVE)
                ->with('user:id,name')
                ->get()
                ->map(fn ($p) => [
                    'user_id' => (string) $p->user_id,
                    'name' => $p->user?->name ?? 'staff',
                ])
                ->toArray();

            throw new MobileSessionActiveException($active);
        }
    }

    /**
     * Fase 2 override: multi-participant self-claim.
     * - Tolak kalau session sudah tertutup (once_received_at set) — F2.
     * - Tolak kalau user pernah WITHDRAWN — F3.
     * - Auto-insert participant ACTIVE kalau belum ada — self-claim.
     */
    protected function assertMobileCanMutate(Model $doc, string $actorId): void
    {
        $doc->refresh();

        if (! $doc instanceof Inbound) {
            // Fallback ke logic default trait untuk model non-Inbound (tidak dipakai
            // di service ini tapi jaga kontrak). Trait tidak ada parent; kalau tipe
            // beda, lempar biar caller sadar.
            throw new UserFacingException(
                title: 'Guard tidak berlaku',
                message: 'assertMobileCanMutate hanya untuk Inbound.',
                status: 500,
                errors: ['code' => 'GUARD_MISUSE'],
            );
        }

        if ($this->isCancelled($doc) || $this->isFinalCompletion($doc)) {
            throw new UserFacingException(
                title: 'Dokumen sudah tidak bisa diubah',
                message: "Inbound berstatus {$doc->status}, mobile tidak bisa menambah receipt.",
                status: 409,
                errors: ['code' => 'INBOUND_LOCKED_FINAL'],
            );
        }

        // F2: sesi sudah ditutup — tidak ada auto-join lagi.
        if ($doc->isSessionClosed()) {
            throw new InboundSessionClosedException($doc->transaction_number);
        }

        $existing = InboundParticipant::where('inbound_id', $doc->id)
            ->where('user_id', $actorId)
            ->first();

        if ($existing) {
            if ($existing->status === InboundParticipant::STATUS_WITHDRAWN) {
                // F3: tidak auto-reactivate.
                throw new UserFacingException(
                    title: 'Anda ditarik dari sesi ini',
                    message: 'Anda ditarik dari sesi penerimaan ini oleh admin. Hubungi admin untuk bergabung ulang.',
                    status: 403,
                    errors: ['code' => 'PARTICIPANT_WITHDRAWN'],
                );
            }
            if ($existing->status === InboundParticipant::STATUS_DONE) {
                // User sudah tandai Selesai; re-scan → re-open participant miliknya.
                $existing->update([
                    'status' => InboundParticipant::STATUS_ACTIVE,
                    'completed_at' => null,
                ]);
            }
            // ACTIVE — biarkan.
            return;
        }

        // Auto-join baru.
        InboundParticipant::create([
            'inbound_id' => $doc->id,
            'user_id' => $actorId,
            'role' => InboundParticipant::ROLE_RECEIVER,
            'joined_at' => now(),
            'status' => InboundParticipant::STATUS_ACTIVE,
        ]);

        if ($doc->receiving_started_at === null) {
            $doc->forceFill(['receiving_started_at' => now()])->save();
        }
    }

    private function inboundLink(string $id): string
    {
        return "/dashboard/barang-masuk/penerimaan/{$id}";
    }

    public function getAllPaginated(int $limit = 10)
    {
        return $this->inboundRepository->getAllPaginated($limit);
    }

    public function getPaginatedItems(string $inboundId, int $perPage = 10)
    {
        return $this->inboundRepository->getPaginatedItems($inboundId, $perPage);
    }

    public function getReceiptsPaginated(string $inboundId, int $perPage = 50)
    {
        return $this->inboundRepository->getReceiptsPaginated($inboundId, $perPage);
    }

    public function getById(string $id): ?Inbound
    {
        return $this->inboundRepository->findById($id);
    }

    private function movementSourceFor(Inbound $inbound): string
    {
        return $inbound->type === Inbound::TYPE_SALES_RETURN ? 'SALES_RETURN' : 'ADJUSTMENT';
    }

    public function createDraft(array $data): Inbound
    {
        return DB::transaction(function () use ($data) {
            $data['transaction_number'] = $data['transaction_number'] ?? 'INB-' . Str::upper(Str::random(8));
            $data['status'] = Inbound::STATUS_DRAFT;

            $inbound = $this->inboundRepository->create($data);

            foreach ($data['items'] as $itemData) {
                $itemData['inbound_id'] = $inbound->id;
                $this->inboundRepository->createItem($itemData);
            }

            return $inbound->load('items');
        });
    }

    /**
     * F5: Auto-create Inbound DRAFT dari PO (tanpa langsung receive).
     * Dipakai listener PO Confirmed dan tombol "Terima Susulan" (isAdditional=true).
     * Idempoten: kalau isAdditional=false dan sudah ada Inbound aktif untuk PO ini → return existing.
     */
    public function createDraftFromPO(PurchaseOrder $po, string $createdBy, bool $isAdditional = false): Inbound
    {
        return DB::transaction(function () use ($po, $createdBy, $isAdditional) {
            if (! $isAdditional) {
                $existing = Inbound::where('source_type', 'purchase_order')
                    ->where('source_id', $po->id)
                    ->where('status', '!=', Inbound::STATUS_CANCELLED)
                    ->first();
                if ($existing) {
                    return $existing->load('items');
                }
            }

            $po->loadMissing('items');

            if ($po->items->isEmpty()) {
                throw new UserFacingException(
                    title: 'PO belum punya item',
                    message: "Auto-create Inbound dari PO {$po->po_number} dibatalkan karena PO belum punya item. "
                        . 'Pastikan items sudah persist (DB::afterCommit) sebelum panggil createDraftFromPO().',
                    status: 500,
                    errors: ['code' => 'PO_ITEMS_EMPTY'],
                );
            }

            do {
                $candidate = 'INB-' . Str::upper(Str::random(8));
            } while (Inbound::where('transaction_number', $candidate)->exists());

            $poNumber = $po->po_number ?? $po->purchase_order_number ?? '';
            $inbound = $this->inboundRepository->create([
                'location_id'        => $po->location_id,
                'transaction_number' => $candidate,
                'reference_number'   => $poNumber ?: null,
                'type'               => Inbound::TYPE_PURCHASE_ORDER,
                'source_type'        => 'purchase_order',
                'source_id'          => $po->id,
                'status'             => Inbound::STATUS_DRAFT,
                'expected_date'      => now(),
                'created_by'         => $createdBy,
                'notes'              => $isAdditional
                    ? "Penerimaan susulan PO {$poNumber}"
                    : "Auto-generated dari PO {$poNumber}",
            ]);

            foreach ($po->items as $poItem) {
                if ($isAdditional) {
                    $remaining = max(0, (int) $poItem->qty - (int) $poItem->received_qty);
                    if ($remaining <= 0) continue;
                    $expectedQty = $remaining;
                } else {
                    $expectedQty = (int) $poItem->qty;
                }

                $this->inboundRepository->createItem([
                    'inbound_id'   => $inbound->id,
                    'item_id'      => $poItem->item_id,
                    'expected_qty' => $expectedQty,
                    'received_qty' => 0,
                ]);
            }

            return $inbound->load('items');
        });
    }

    public function receiveFromPO(array $data): Inbound
    {
        $data['type'] = Inbound::TYPE_PURCHASE_ORDER;
        $data['source_type'] = 'purchase_order';

        return DB::transaction(function () use ($data) {
            $items = $data['items'] ?? [];
            unset($data['items']);

            // transaction_number harus unique auto-generate; reference_number
            // (dari user "No. Ref" atau PO number) BUKAN sumber transaction_number.
            // Retry sampai dapat yang tidak collision (probabilitas tabrakan random 8 char sangat kecil).
            do {
                $candidate = 'INB-' . Str::upper(Str::random(8));
            } while (Inbound::where('transaction_number', $candidate)->exists());
            $data['transaction_number'] = $candidate;
            $data['status'] = Inbound::STATUS_DRAFT;

            $inbound = $this->inboundRepository->create($data);

            $receiveItems = [];
            foreach ($items as $itemData) {
                $acceptedQty = $itemData['accepted_qty'] ?? $itemData['expected_qty'];
                $rejectedQty = $itemData['rejected_qty'] ?? 0;

                $inboundItem = $this->inboundRepository->createItem([
                    'inbound_id'     => $inbound->id,
                    'item_id'        => $itemData['item_id'],
                    'expected_qty'   => $itemData['expected_qty'],
                    'received_qty'   => 0,
                    'rejected_qty'   => $rejectedQty,
                    'rejection_note' => $itemData['rejection_note'] ?? null,
                    'condition'      => $rejectedQty > 0 ? 'PARTIAL' : 'GOOD',
                    'notes'          => $itemData['notes'] ?? null,
                ]);

                if ($acceptedQty > 0) {
                    $receiveItems[] = [
                        'inbound_item_id' => $inboundItem->id,
                        'qty'             => $acceptedQty,
                        'condition'       => 'GOOD',
                    ];
                }
            }

            if (empty($receiveItems)) {
                $inbound->update(['status' => Inbound::STATUS_RECEIVED]);
                return $inbound->load('items');
            }

            return $this->receive($inbound->id, [
                'received_by' => $data['created_by'],
                'items'       => $receiveItems,
            ]);
        });
    }

    public function receiveFromTransfer(array $data): Inbound
    {
        $data['type'] = Inbound::TYPE_TRANSIT_IN;
        $data['source_type'] = 'transfer';

        return DB::transaction(function () use ($data) {
            $items = $data['items'] ?? [];
            unset($data['items']);

            $data['transaction_number'] = $data['transaction_number'] ?? app(InventoryService::class)->generateTrfiNumber();

            $data['status'] = Inbound::STATUS_RECEIVED;

            $inbound = $this->inboundRepository->create($data);

            foreach ($items as $itemData) {
                $this->inboundRepository->createItem([
                    'inbound_id'   => $inbound->id,
                    'item_id'      => $itemData['item_id'],
                    'expected_qty' => $itemData['expected_qty'],
                    'received_qty' => $itemData['expected_qty'],
                ]);
            }

            return $inbound->load('items');
        });
    }

    public function createPendingTransit(array $data): Inbound
    {
        $existing = $this->inboundRepository->findBySource('transfer', $data['source_id']);
        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($data) {
            $inbound = $this->inboundRepository->create([
                'location_id'        => $data['location_id'],
                'transaction_number' => $data['transaction_number'] ?? app(InventoryService::class)->generateTrfiNumber(),
                'reference_number'   => $data['reference_number'] ?? null,
                'type'               => Inbound::TYPE_TRANSIT_IN,
                'source_type'        => 'transfer',
                'source_id'          => $data['source_id'],
                'status'             => Inbound::STATUS_DRAFT,
                'expected_date'      => $data['expected_date'] ?? now(),
                'created_by'         => $data['created_by'],
            ]);

            foreach ($data['items'] as $itemData) {
                $this->inboundRepository->createItem([
                    'inbound_id'   => $inbound->id,
                    'item_id'      => $itemData['item_id'],
                    'expected_qty' => $itemData['expected_qty'],
                    'received_qty' => 0,
                ]);
            }

            return $inbound->load('items');
        });
    }

    public function syncTransitReceived(string $transferId, array $data): Inbound
    {
        $inbound = $this->inboundRepository->findBySource('transfer', $transferId);

        if (! $inbound) {
            return $this->receiveFromTransfer($data);
        }

        $inbound = DB::transaction(function () use ($inbound, $data) {
            $receivedByItem = collect($data['items'] ?? [])->keyBy('item_id');
            $anyShortfall = false;

            foreach ($inbound->items as $item) {
                $receivedQty = (int) ($receivedByItem[$item->item_id]['received_qty'] ?? $item->expected_qty);
                $item->update(['received_qty' => $receivedQty]);
                if ($receivedQty < $item->expected_qty) {
                    $anyShortfall = true;
                }
            }

            $inbound->update([
                'status'             => $anyShortfall ? Inbound::STATUS_PARTIAL : Inbound::STATUS_RECEIVED,
                // Jangan overwrite transaction_number dengan reference_number
                // (collision unique kalau ref_number kebetulan sama dengan tx_number
                // dokumen lain). Keep existing tx_number sebagai default aman.
                'transaction_number' => $data['transaction_number'] ?? $inbound->transaction_number,
            ]);

            return $inbound->load('items');
        });

        $shortfallSuffix = $inbound->status === Inbound::STATUS_PARTIAL ? ' (sebagian kurang)' : '';
        $this->notifications->toPermission(self::NOTIF_PENEMPATAN, [
            'type' => 'transfer_transit_received',
            'title' => 'Transfer masuk diterima',
            'message' => "Transfer {$inbound->transaction_number} tiba di gudang{$shortfallSuffix}.",
            'data' => [
                'inbound_id' => $inbound->id,
                'transaction_number' => $inbound->transaction_number,
                'transfer_id' => $transferId,
                'link' => $this->inboundLink($inbound->id),
            ],
        ]);

        return $inbound;
    }

    public function receiveFromSalesReturn(array $data): Inbound
    {
        $data['type'] = Inbound::TYPE_SALES_RETURN;
        $data['source_type'] = 'sales_return';
        return $this->createDraft($data);
    }

    public function receiveFromConsignment(array $data): Inbound
    {
        $data['type'] = Inbound::TYPE_CONSIGNMENT;
        $data['source_type'] = 'consignment';
        return $this->createDraft($data);
    }

    public function receive(string $inboundId, array $data): Inbound
    {
        $result = DB::transaction(function () use ($inboundId, $data) {
            $inbound = $this->inboundRepository->findByIdForUpdate($inboundId);

            if (! $inbound) {
                throw new \Exception("Dokumen Inbound tidak ditemukan.");
            }

            // Channel-aware guard:
            // - Web (admin buat Penerimaan Barang manual, tanpa assign staff mobile) → assertWebCanMutate.
            //   Sah kalau dokumen belum di-assign atau sudah pernah RECEIVED (once_received_at).
            // - Mobile (staff scan dari HP) → assertMobileCanMutate STRICT (H1) —
            //   wajib ada assignment, actor = assignee.
            if ($this->currentChannel() === \App\Enums\ClientChannelEnum::MOBILE) {
                $this->assertMobileCanMutate($inbound, (string) $data['received_by']);
            } else {
                $this->assertWebCanMutate($inbound);
            }

            if (! $inbound->isReceivable()) {
                throw new \Exception("Inbound sudah berstatus {$inbound->status}, tidak bisa menerima barang.");
            }

            $defaultBin = $this->binService->getDefaultBin($inbound->location_id);
            if (! $defaultBin) {
                throw new \Exception("Gudang ini belum memiliki Bin Inbound default.");
            }

            $itemsDict = $inbound->items->keyBy('id');

            $landedCostMap = $this->resolveLandedCostMap($inbound);

            // Resolve received_by_user_id sekali di luar loop.
            // Prioritas: auth()->id() > string received_by kalau UUID valid > null.
            $receivedByUserId = auth()->id();
            if (! $receivedByUserId) {
                $candidate = (string) ($data['received_by'] ?? '');
                if (\Illuminate\Support\Str::isUuid($candidate)) {
                    $receivedByUserId = $candidate;
                }
            }

            foreach ($data['items'] as $receiptData) {
                $inboundItem = $itemsDict->get($receiptData['inbound_item_id']);
                if (! $inboundItem) {
                    throw new \Exception("Item ID {$receiptData['inbound_item_id']} tidak terkait dengan Inbound ini.");
                }

                $condition = $receiptData['condition'] ?? 'GOOD';
                $isDamage = $condition === 'DAMAGE';

                // F1 (keputusan Darel 15 Jul): cap `newTotal > expected_qty` DILEPAS.
                // Mobile tidak lihat expected; staff scan sesuai fisik. Over/under receipt
                // (positif atau negatif) normal, admin koreksi via edit web setelah semua Selesai.

                $this->inboundRepository->createReceipt([
                    'inbound_item_id'     => $inboundItem->id,
                    'qty'                 => $receiptData['qty'],
                    'bin_id'              => $defaultBin->id,
                    'batch_no'            => $receiptData['batch_no'] ?? null,
                    'serial_no'           => $receiptData['serial_no'] ?? null,
                    'condition'           => $condition,
                    'received_by_user_id' => $receivedByUserId,
                    'received_date'       => now(),
                ]);

                if ($isDamage) {
                    $this->inboundRepository->updateItemRejectedQty($inboundItem->id, $receiptData['qty']);
                    $inboundItem->rejected_qty = ($inboundItem->rejected_qty ?? 0) + $receiptData['qty'];
                } else {
                    $this->inboundRepository->updateItemReceivedQty($inboundItem->id, $receiptData['qty']);
                    $inboundItem->received_qty += $receiptData['qty'];
                }

                if (! $isDamage) {
                    $this->inventoryService->adjust([
                        'item_id'            => $inboundItem->item_id,
                        'location_id'        => $inbound->location_id,
                        'bin_id'             => $defaultBin->id,
                        'batch_no'           => $receiptData['batch_no'] ?? '',
                        'serial_no'          => $receiptData['serial_no'] ?? '',
                        'qty'                => $receiptData['qty'],
                        'transaction_number' => $inbound->transaction_number,
                        'source'             => $this->movementSourceFor($inbound),
                        'created_by'         => $data['received_by'],
                    ]);
                }

                $landedCost = (float) ($landedCostMap[$inboundItem->item_id] ?? 0);
                if ($landedCost > 0 && ! $isDamage) {
                    $this->inventoryService->recalculateAverageCost(
                        $inboundItem->item_id,
                        $inbound->location_id,
                        $defaultBin->id,
                        (float) $receiptData['qty'],
                        $landedCost,
                        $receiptData['batch_no'] ?? '',
                        $receiptData['serial_no'] ?? '',
                    );

                    InventoryMovement::where('transaction_number', $inbound->transaction_number)
                        ->where('item_id', $inboundItem->item_id)
                        ->where('location_id', $inbound->location_id)
                        ->where('bin_id', $defaultBin->id)
                        ->where('qty', $receiptData['qty'])
                        ->whereNull('cost_per_unit')
                        ->orderByDesc('id')
                        ->limit(1)
                        ->update([
                            'cost_per_unit' => $landedCost,
                            'total_cost'    => round($landedCost * (float) $receiptData['qty'], 2),
                        ]);
                }
            }

            // Fase E (16 Jul): admin-only finalize. Receive event tidak pernah
            // menaikkan status ke RECEIVED otomatis. Status max PARTIAL selama
            // belum di-close oleh admin via closeReceiving().
            $this->inboundRepository->updateStatus($inbound, Inbound::STATUS_PARTIAL);

            // F9: bump updated_version_at supaya optimistic lock web tetap valid pasca mobile mutate.
            $inbound->forceFill(['updated_version_at' => now()])->save();

            return $this->getById($inboundId);
        });

        return $result;
    }

    public function closeReceiving(string $inboundId, string $closedBy): Inbound
    {
        $result = DB::transaction(function () use ($inboundId, $closedBy) {
            $inbound = $this->inboundRepository->findByIdForUpdate($inboundId);

            if (! $inbound) {
                throw new \Exception("Dokumen Inbound tidak ditemukan.");
            }

            $closeable = in_array($inbound->status, [
                Inbound::STATUS_DRAFT,
                Inbound::STATUS_PARTIAL,
                Inbound::STATUS_RECEIVED,
            ]);

            if (! $closeable) {
                throw new \Exception("Inbound sudah berstatus {$inbound->status}.");
            }

            foreach ($inbound->items as $item) {
                $disc = $item->expected_qty - $item->received_qty - ($item->rejected_qty ?? 0);
                if ($disc > 0) {
                    $this->inboundRepository->updateItemDiscrepancy(
                        $item->id,
                        $disc,
                        "Closed by {$closedBy}. Expected {$item->expected_qty}, received {$item->received_qty}, rejected {$item->rejected_qty}"
                    );
                }
            }

            // Fase E: admin close = force-withdraw semua participant ACTIVE.
            // Session mobile ditutup otomatis; lock web dilepas.
            InboundParticipant::where('inbound_id', $inbound->id)
                ->where('status', InboundParticipant::STATUS_ACTIVE)
                ->update([
                    'status' => InboundParticipant::STATUS_WITHDRAWN,
                    'withdrawn_by' => $closedBy,
                    'withdraw_reason' => 'admin_finalize',
                    'withdrawn_at' => now(),
                ]);

            $this->inboundRepository->updateStatus($inbound, Inbound::STATUS_RECEIVED);
            $inbound->forceFill(['updated_version_at' => now()])->save();

            return $this->getById($inboundId);
        });

        if ($result && $result->status === Inbound::STATUS_RECEIVED) {
            $this->notifications->toPermission(self::NOTIF_PENEMPATAN, [
                'type' => 'inbound_received',
                'title' => 'Penerimaan selesai, siap penempatan',
                'message' => "Dokumen {$result->transaction_number} sudah diselesaikan dan siap di-putaway.",
                'data' => [
                    'inbound_id' => $result->id,
                    'transaction_number' => $result->transaction_number,
                    'link' => $this->inboundLink($result->id),
                ],
            ], excludeUserIds: array_filter([$closedBy]));
        }

        return $result;
    }

    public function processPutaway(string $inboundId, array $data): Inbound
    {
        return DB::transaction(function () use ($inboundId, $data) {
            $inbound = $this->inboundRepository->findByIdForUpdate($inboundId);

            if (! $inbound) {
                throw new \Exception("Dokumen Inbound tidak ditemukan.");
            }

            if (! $inbound->isPutawayable()) {
                throw new \Exception("Inbound berstatus {$inbound->status}, tidak bisa di-putaway. Harus berstatus RECEIVED terlebih dahulu.");
            }

            $defaultBin = $this->binService->getDefaultBin($inbound->location_id);
            if (! $defaultBin) {
                throw new \Exception("Gudang ini belum memiliki Bin Inbound default.");
            }

            $itemsDict = $inbound->items->keyBy('id');

            foreach ($data['putaway_items'] as $putawayItem) {
                $inboundItem = $itemsDict->get($putawayItem['inbound_item_id']);
                if (! $inboundItem) {
                    throw new \Exception("Item ID {$putawayItem['inbound_item_id']} tidak terkait dengan Inbound ini.");
                }

                $pendingQty = $inboundItem->pendingPutawayQty();
                if ($putawayItem['qty'] > $pendingQty) {
                    throw new \Exception("Qty putaway ({$putawayItem['qty']}) melebihi pending putaway ({$pendingQty}) untuk item {$inboundItem->item_id}.");
                }

                $this->inventoryService->putaway([
                    'item_id'            => $inboundItem->item_id,
                    'location_id'        => $inbound->location_id,
                    'source_bin_id'      => $defaultBin->id,
                    'destination_bin_id' => $putawayItem['destination_bin_id'],
                    'qty'                => $putawayItem['qty'],
                    'batch_no'           => $putawayItem['batch_no'] ?? '',
                    'serial_no'          => $putawayItem['serial_no'] ?? '',
                    'created_by'         => $data['created_by'],
                ]);

                $this->inboundRepository->updateItemPutawayQty($inboundItem->id, $putawayItem['qty']);
                $inboundItem->putaway_qty += $putawayItem['qty'];
            }

            $this->resolveInboundPutawayStatus($inbound);

            return $this->getById($inboundId);
        });
    }

    public function autoPutaway(string $inboundId, string $createdBy): Inbound
    {
        return DB::transaction(function () use ($inboundId, $createdBy) {
            $inbound = $this->inboundRepository->findByIdForUpdate($inboundId);

            if (! $inbound) {
                throw new \Exception("Dokumen Inbound tidak ditemukan.");
            }

            if (! $inbound->isPutawayable()) {
                throw new \Exception("Inbound berstatus {$inbound->status}, tidak bisa di-putaway.");
            }

            $defaultBin = $this->binService->getDefaultBin($inbound->location_id);
            if (! $defaultBin) {
                throw new \Exception("Gudang ini belum memiliki Bin Inbound default.");
            }

            $pendingItems = $this->inboundRepository->getItemsPendingPutaway($inboundId);
            if ($pendingItems->isEmpty()) {
                throw new \Exception("Tidak ada item yang perlu di-putaway.");
            }

            $availableBins = $this->binService->getByLocation($inbound->location_id)
                ->where('is_inbound', false);

            if ($availableBins->isEmpty()) {
                throw new \Exception("Tidak ada bin tujuan yang tersedia di gudang ini.");
            }

            $firstBin = $availableBins->first();

            foreach ($pendingItems as $item) {
                $pendingQty = $item->pendingPutawayQty();
                if ($pendingQty <= 0) {
                    continue;
                }

                $this->inventoryService->putaway([
                    'item_id'            => $item->item_id,
                    'location_id'        => $inbound->location_id,
                    'source_bin_id'      => $defaultBin->id,
                    'destination_bin_id' => $firstBin->id,
                    'qty'                => $pendingQty,
                    'batch_no'           => '',
                    'serial_no'          => '',
                    'created_by'         => $createdBy,
                ]);

                $this->inboundRepository->updateItemPutawayQty($item->id, $pendingQty);
            }

            $inbound->load('items');
            $this->resolveInboundPutawayStatus($inbound);

            return $this->getById($inboundId);
        });
    }

    public function getReceivedItemsPaginated(int $limit = 10)
    {
        return $this->inboundRepository->getReceivedItemsPaginated($limit);
    }

    public function getItemsPendingPutaway(string $inboundId)
    {
        return $this->inboundRepository->getItemsPendingPutaway($inboundId);
    }

    public function assignWorker(string $inboundId, string $assignedTo, string $assignedBy, ?string $notes = null): InboundAssignment
    {
        return DB::transaction(function () use ($inboundId, $assignedTo, $assignedBy, $notes) {
            $inbound = $this->inboundRepository->findByIdForUpdate($inboundId);

            if (! $inbound) {
                throw new \Exception("Dokumen Inbound tidak ditemukan.");
            }

            if ($inbound->status === Inbound::STATUS_CANCELLED || $inbound->status === Inbound::STATUS_COMPLETED) {
                throw new \Exception("Inbound berstatus {$inbound->status}, tidak bisa di-assign.");
            }

            $previousAssignee = $inbound->assigned_to;
            $action = $previousAssignee === null
                ? AssignmentActionEnum::ASSIGN
                : AssignmentActionEnum::REASSIGN;

            $assignment = $this->inboundRepository->createAssignment([
                'inbound_id'  => $inboundId,
                'assigned_to' => $assignedTo,
                'assigned_by' => $assignedBy,
                'status'      => InboundAssignment::STATUS_PENDING,
                'notes'       => $notes,
            ]);

            // Denormalize ke inbounds.assigned_to untuk guard channel lock.
            $inbound->forceFill([
                'assigned_to' => $assignedTo,
                'assigned_by' => $assignedBy,
                'assigned_at' => now(),
                'updated_version_at' => now(),
            ])->save();

            $this->recordHistory($inbound, $previousAssignee, $assignedTo, $assignedBy, $action);

            TaskAssigned::dispatch(
                $assignedTo,
                'inbound',
                $inbound->transaction_number,
                $assignedBy,
                ['inbound_id' => $inboundId, 'assignment_id' => $assignment->id],
            );

            return $assignment;
        });
    }

    /**
     * Tombol A "Alihkan Tugas" — TAHAN progress, opsional reassign ke user baru.
     */
    public function unassignWorker(
        string $inboundId,
        string $actorId,
        UnassignReasonEnum $reason,
        ?string $reasonNote = null,
        ?string $newAssigneeId = null,
    ): Inbound {
        return DB::transaction(function () use ($inboundId, $actorId, $reason, $reasonNote, $newAssigneeId) {
            $inbound = $this->inboundRepository->findByIdForUpdate($inboundId);
            if (! $inbound) {
                throw new \Exception('Dokumen Inbound tidak ditemukan.');
            }

            $previousAssignee = $inbound->assigned_to;
            $isSelf = $previousAssignee !== null && (string) $previousAssignee === $actorId;
            $action = $isSelf ? AssignmentActionEnum::SELF_UNASSIGN : AssignmentActionEnum::UNASSIGN;

            // Progress TAHAN — hanya swap assignee (atau null).
            InboundAssignment::where('inbound_id', $inboundId)
                ->whereIn('status', [InboundAssignment::STATUS_PENDING, InboundAssignment::STATUS_IN_PROGRESS])
                ->update([
                    'status' => InboundAssignment::STATUS_COMPLETED,
                    'completed_at' => now(),
                ]);

            $inbound->forceFill([
                'assigned_to' => $newAssigneeId,
                'assigned_by' => $newAssigneeId ? $actorId : null,
                'assigned_at' => $newAssigneeId ? now() : null,
                'updated_version_at' => now(),
            ])->save();

            if ($newAssigneeId) {
                $this->inboundRepository->createAssignment([
                    'inbound_id'  => $inboundId,
                    'assigned_to' => $newAssigneeId,
                    'assigned_by' => $actorId,
                    'status'      => InboundAssignment::STATUS_PENDING,
                    'notes'       => "Handover: {$reason->label()}",
                ]);
            }

            $this->recordHistory(
                $inbound,
                $previousAssignee,
                $newAssigneeId,
                $actorId,
                $action,
                $reason,
                $reasonNote,
            );

            return $inbound->fresh();
        });
    }

    /**
     * Tombol B "Reset & Alihkan" — reverse received_qty ke 0 per item + audit.
     * Guard: tolak kalau ada putaway aktif turunan (fix planning — putaway data
     * jadi inconsistent kalau received_qty dihapus).
     * Guard: tolak kalau mobile session aktif tanpa unassign dulu (fix H2).
     */
    public function resetAssignment(
        string $inboundId,
        string $actorId,
        string $reasonNote,
        ?string $newAssigneeId = null,
    ): Inbound {
        return DB::transaction(function () use ($inboundId, $actorId, $reasonNote, $newAssigneeId) {
            $inbound = $this->inboundRepository->findByIdForUpdate($inboundId);
            if (! $inbound) {
                throw new \Exception('Dokumen Inbound tidak ditemukan.');
            }

            // Fix H2: tolak kalau mobile session masih aktif (PARTIAL + assigned).
            if ($inbound->assigned_to !== null
                && $inbound->status === Inbound::STATUS_PARTIAL) {
                throw new AssignmentLockException(
                    assignedToName: $this->resolveUserName($inbound->assigned_to),
                    assignedToId: (string) $inbound->assigned_to,
                    assignedAt: $inbound->assigned_at?->toDateTimeString(),
                );
            }

            // Guard planning: blok kalau ada putaway aktif turunan.
            $activePutaways = Putaway::whereHas('sources', fn ($q) => $q->where('inbound_id', $inboundId))
                ->whereIn('status', [Putaway::STATUS_NOT_STARTED, Putaway::STATUS_IN_PROGRESS])
                ->pluck('putaway_no')
                ->toArray();

            if (! empty($activePutaways)) {
                throw new PutawayActiveException($activePutaways);
            }

            $defaultBin = $this->binService->getDefaultBin($inbound->location_id);
            if (! $defaultBin) {
                throw new \Exception('Gudang ini belum memiliki Bin Inbound default.');
            }

            $previousAssignee = $inbound->assigned_to;

            $inbound->load('items');
            foreach ($inbound->items as $item) {
                $received = (int) $item->received_qty;
                if ($received <= 0) {
                    continue;
                }

                $this->inventoryService->adjust([
                    'item_id'            => $item->item_id,
                    'location_id'        => $inbound->location_id,
                    'bin_id'             => $defaultBin->id,
                    'qty'                => -$received,
                    'transaction_number' => $inbound->transaction_number . '-RESET',
                    'source'             => $this->movementSourceFor($inbound),
                    'created_by'         => "user:{$actorId}",
                ]);

                $this->inboundRepository->updateItemReceivedQty($item->id, -$received);
            }

            $inbound->forceFill([
                'assigned_to' => $newAssigneeId,
                'assigned_by' => $newAssigneeId ? $actorId : null,
                'assigned_at' => $newAssigneeId ? now() : null,
                'status'      => Inbound::STATUS_DRAFT,
                'updated_version_at' => now(),
            ])->save();

            $this->recordHistory(
                $inbound,
                $previousAssignee,
                $newAssigneeId,
                $actorId,
                AssignmentActionEnum::FORCE_RESET,
                UnassignReasonEnum::FORCE_RESET,
                $reasonNote,
            );

            return $inbound->fresh();
        });
    }

    /**
     * Explicit join tanpa scan — mobile buka layar tapi belum scan apa-apa.
     */
    public function joinSession(string $inboundId, string $userId): Inbound
    {
        return DB::transaction(function () use ($inboundId, $userId) {
            $inbound = $this->inboundRepository->findByIdForUpdate($inboundId);
            if (! $inbound) {
                throw new \Exception('Dokumen Inbound tidak ditemukan.');
            }

            $this->assertMobileCanMutate($inbound, $userId);

            return $inbound->fresh('participants');
        });
    }

    /**
     * Admin tarik participant dari web. Receipts milik participant tetap ada (append-only).
     */
    public function withdrawParticipant(
        string $inboundId,
        string $targetUserId,
        string $actorId,
        ?string $reasonNote = null,
    ): Inbound {
        return DB::transaction(function () use ($inboundId, $targetUserId, $actorId, $reasonNote) {
            $inbound = $this->inboundRepository->findByIdForUpdate($inboundId);
            if (! $inbound) {
                throw new \Exception('Dokumen Inbound tidak ditemukan.');
            }

            $participant = InboundParticipant::where('inbound_id', $inboundId)
                ->where('user_id', $targetUserId)
                ->lockForUpdate()
                ->first();

            if (! $participant) {
                throw new UserFacingException(
                    title: 'Peserta tidak ditemukan',
                    message: 'User target bukan peserta sesi ini.',
                    status: 404,
                    errors: ['code' => 'PARTICIPANT_NOT_FOUND'],
                );
            }

            if ($participant->status !== InboundParticipant::STATUS_ACTIVE) {
                throw new UserFacingException(
                    title: 'Tidak perlu ditarik',
                    message: 'Peserta sudah tidak aktif (status: ' . $participant->status . ').',
                    status: 409,
                    errors: ['code' => 'PARTICIPANT_NOT_ACTIVE'],
                );
            }

            $participant->update([
                'status' => InboundParticipant::STATUS_WITHDRAWN,
                'withdrawn_by' => $actorId,
                'withdraw_reason' => $reasonNote,
                'withdrawn_at' => now(),
            ]);

            // Fase E: withdraw hanya melepas 1 participant. Status inbound TIDAK naik
            // ke RECEIVED otomatis — hanya admin closeReceiving yang bisa. Tapi bump
            // updated_version_at supaya FE tahu ada perubahan participant.
            $inbound->forceFill(['updated_version_at' => now()])->save();

            return $inbound->fresh();
        });
    }

    private function recordHistory(
        Inbound $inbound,
        ?string $fromUserId,
        ?string $toUserId,
        ?string $actorId,
        AssignmentActionEnum $action,
        ?UnassignReasonEnum $reason = null,
        ?string $reasonNote = null,
    ): void {
        AssignmentHistory::create([
            'subject_type' => Inbound::class,
            'subject_id'   => $inbound->id,
            'from_user_id' => $fromUserId,
            'to_user_id'   => $toUserId,
            'actor_id'     => $actorId,
            'action'       => $action->value,
            'channel'      => $this->currentChannel()->value,
            'reason_code'  => $reason?->value,
            'reason_note'  => $reasonNote,
        ]);
    }

    public function getAssignments(string $inboundId)
    {
        return $this->inboundRepository->getAssignmentsByInbound($inboundId);
    }

    public function getMyAssignments(string $userId, ?string $status = null, int $perPage = 10, ?string $search = null, string $sort = '-created_at')
    {
        return $this->inboundRepository->getAssignmentsByWorker($userId, $status, $perPage, $search, $sort);
    }

    public function startAssignment(string $assignmentId, string $userId): InboundAssignment
    {
        $assignment = InboundAssignment::findOrFail($assignmentId);

        if ($assignment->assigned_to !== $userId) {
            throw new \Exception("Assignment ini bukan milik Anda.");
        }

        if ($assignment->status !== InboundAssignment::STATUS_PENDING) {
            throw new \Exception("Assignment sudah berstatus {$assignment->status}.");
        }

        $assignment->update([
            'status'     => InboundAssignment::STATUS_IN_PROGRESS,
            'started_at' => now(),
        ]);

        return $assignment->fresh()->load('inbound.items', 'worker:id,name');
    }

    /**
     * Legacy signature — return single item. Pertahankan untuk backward-compat
     * pemanggil lain. Untuk mobile dengan disambiguation multi-inbound, pakai
     * lookupCandidatesByQr() (F7).
     */
    public function lookupByQr(string $code, ?string $inboundId = null): InboundItem
    {
        $code = trim($code);
        $item = null;

        // UUID lookup hanya kalau format-nya UUID valid. Kalau langsung
        // query dengan string SKU, Postgres crash di parser karena kolom
        // id bertype uuid (SQLSTATE 22P02).
        if (Str::isUuid($code)) {
            $item = $this->inboundRepository->findItemByUuid($code);
            // Kalau ditemukan tapi bukan milik inbound yang di-scope, tolak.
            if ($item && $inboundId && $item->inbound_id !== $inboundId) {
                $item = null;
            }
        }

        if (! $item) {
            $query = InboundItem::whereHas('variant', fn ($q) => $q->where('sku', $code)->orWhere('barcode', $code));

            if ($inboundId) {
                // Scope ke inbound yang user buka — SKU sama di banyak inbound
                // tidak akan salah pilih.
                $query->where('inbound_id', $inboundId);
            } else {
                // Tanpa scope: fallback ke inbound aktif, ambil yang paling baru.
                $query->whereHas('inbound', fn ($q) => $q->whereIn('status', [
                    Inbound::STATUS_DRAFT,
                    Inbound::STATUS_PARTIAL,
                ]))->latest();
            }

            $item = $query->first();
        }

        if (! $item) {
            throw new \Exception("QR Code tidak ditemukan.");
        }

        return $item->load('inbound.location', 'variant:id,sku,product_id');
    }

    /**
     * F7: return semua kandidat inbound item yang match SKU/barcode/QR — mobile
     * disambiguation bottom-sheet. Kalau UUID (langsung inbound_item.id) hanya 1.
     */
    public function lookupCandidatesByQr(string $code): \Illuminate\Support\Collection
    {
        $code = trim($code);

        // 1. UUID (inbound_item.id) → 1 hasil pasti. Guard Str::isUuid supaya
        //    Postgres tidak crash 22P02 saat code adalah SKU/barcode.
        if (Str::isUuid($code)) {
            $direct = $this->inboundRepository->findItemByUuid($code);
            if ($direct) {
                return collect([$direct->load('inbound.location', 'variant:id,sku,product_id')]);
            }
        }

        // 2. SKU/barcode → semua inbound aktif yang ada item ini.
        return InboundItem::whereHas('variant', fn ($q) => $q->where('sku', $code)->orWhere('barcode', $code))
            ->whereHas('inbound', fn ($q) => $q->whereIn('status', [
                Inbound::STATUS_DRAFT,
                Inbound::STATUS_PARTIAL,
            ]))
            ->with('inbound.location', 'variant:id,sku,product_id')
            ->latest()
            ->get();
    }

    public function scanPutaway(string $inboundItemId, string $binId, int $qty, string $userId): InboundItem
    {
        return DB::transaction(function () use ($inboundItemId, $binId, $qty, $userId) {
            $inboundItem = $this->inboundRepository->findItemByUuidForUpdate($inboundItemId);

            if (! $inboundItem) {
                throw new \Exception("QR Code barang tidak ditemukan.");
            }

            $destinationBin = \Modules\Warehouse\Models\LocationBin::find($binId);

            if (! $destinationBin) {
                throw new \Exception("QR Code rak tidak ditemukan.");
            }

            $inbound = $inboundItem->inbound;

            if (! $inbound->isPutawayable() && $inbound->status !== Inbound::STATUS_RECEIVED) {
                throw new \Exception("Inbound berstatus {$inbound->status}, tidak bisa di-putaway.");
            }

            $pendingQty = $inboundItem->pendingPutawayQty();
            if ($qty > $pendingQty) {
                throw new \Exception("Qty putaway ({$qty}) melebihi pending putaway ({$pendingQty}).");
            }

            $defaultBin = $this->binService->getDefaultBin($inbound->location_id);
            if (! $defaultBin) {
                throw new \Exception("Gudang ini belum memiliki Bin Inbound default.");
            }

            $this->inventoryService->putaway([
                'item_id'            => $inboundItem->item_id,
                'location_id'        => $inbound->location_id,
                'source_bin_id'      => $defaultBin->id,
                'destination_bin_id' => $destinationBin->id,
                'qty'                => $qty,
                'batch_no'           => '',
                'serial_no'          => '',
                'created_by'         => "user:{$userId}",
            ]);

            $this->inboundRepository->updateItemPutawayQty($inboundItem->id, $qty);

            $inbound->load('items');
            $this->resolveInboundPutawayStatus($inbound);

            $this->completeAssignmentIfDone($inbound, $userId);

            return $inboundItem->fresh()->load('inbound', 'variant:id,sku,product_id');
        });
    }

    private function completeAssignmentIfDone(Inbound $inbound, string $userId): void
    {
        if ($inbound->status !== Inbound::STATUS_COMPLETED) {
            return;
        }

        $inbound->assignments()
            ->where('assigned_to', $userId)
            ->where('status', InboundAssignment::STATUS_IN_PROGRESS)
            ->update([
                'status'       => InboundAssignment::STATUS_COMPLETED,
                'completed_at' => now(),
            ]);
    }

    public function correctReceivedLine(string $inboundId, string $inboundItemId, ?int $qty, string $userId): Inbound
    {
        return $this->correctReceivedLines($inboundId, [
            ['item_id' => $inboundItemId, 'qty' => $qty],
        ], $userId);
    }

    public function correctReceivedLines(string $inboundId, array $items, string $userId): Inbound
    {
        if (empty($items)) {
            throw new \Exception('Tidak ada baris yang dipilih untuk dikoreksi.');
        }

        return DB::transaction(function () use ($inboundId, $items, $userId) {
            $inbound = $this->inboundRepository->findByIdForUpdate($inboundId);

            if (! $inbound) {
                throw new \Exception("Dokumen Inbound tidak ditemukan.");
            }

            if ($inbound->status === Inbound::STATUS_CANCELLED) {
                throw new \Exception("Inbound sudah dibatalkan.");
            }

            // Fix C1: koreksi web hanya boleh setelah sekali RECEIVED atau belum di-assign.
            $this->assertWebCanMutate($inbound);

            $defaultBin = $this->binService->getDefaultBin($inbound->location_id);
            if (! $defaultBin) {
                throw new \Exception("Gudang ini belum memiliki Bin Inbound default.");
            }

            foreach ($items as $entry) {
                $inboundItemId = $entry['item_id'] ?? null;
                $qty = $entry['qty'] ?? null;

                if (! $inboundItemId) {
                    throw new \Exception('item_id wajib diisi.');
                }

                $item = $this->inboundRepository->findItemByUuidForUpdate($inboundItemId);

                if (! $item || $item->inbound_id !== $inbound->id) {
                    throw new \Exception("Item inbound tidak ditemukan.");
                }

                $qtyRev = $qty ?? (int) $item->received_qty;

                if ($qtyRev <= 0 || $qtyRev > (int) $item->received_qty) {
                    throw new \Exception("Qty koreksi tidak valid (maksimal {$item->received_qty}).");
                }

                $available = (int) $item->received_qty - (int) $item->putaway_qty;
                if ($qtyRev > $available) {
                    throw new \Exception("Sebagian barang sudah di-putaway; hanya {$available} unit yang masih di bin inbound dan bisa dikoreksi.");
                }

                $this->inventoryService->adjust([
                    'item_id'            => $item->item_id,
                    'location_id'        => $inbound->location_id,
                    'bin_id'             => $defaultBin->id,
                    'qty'                => -$qtyRev,
                    'transaction_number' => $inbound->transaction_number . '-KOREKSI',
                    'source'             => $this->movementSourceFor($inbound),
                    'created_by'         => "user:{$userId}",
                ]);

                $this->inboundRepository->updateItemReceivedQty($item->id, -$qtyRev);
            }

            return $this->getById($inboundId);
        });
    }

    public function setReceivedQty(string $inboundId, string $inboundItemId, int $targetQty, string $userId, ?string $expectedUpdatedAt = null): Inbound
    {
        if ($targetQty < 0) {
            throw new \Exception('Jumlah tidak boleh negatif.');
        }

        return DB::transaction(function () use ($inboundId, $inboundItemId, $targetQty, $userId, $expectedUpdatedAt) {
            $inbound = $this->inboundRepository->findByIdForUpdate($inboundId);
            if (! $inbound) {
                throw new \Exception('Dokumen Inbound tidak ditemukan.');
            }
            if ($inbound->status === Inbound::STATUS_CANCELLED) {
                throw new \Exception('Inbound sudah dibatalkan.');
            }

            // Fix C1 (guard web via once_received_at, bukan status current).
            $this->assertWebCanMutate($inbound);

            // Fix H4 (optimistic lock — cegah 2 admin edit bareng).
            $this->assertVersionMatches($inbound, $expectedUpdatedAt);

            $item = $this->inboundRepository->findItemByUuidForUpdate($inboundItemId);
            if (! $item || $item->inbound_id !== $inbound->id) {
                throw new \Exception('Item inbound tidak ditemukan.');
            }

            $current = (int) $item->received_qty;
            $delta = $targetQty - $current;
            if ($delta === 0) {
                return $this->getById($inboundId);
            }

            if ($targetQty < (int) $item->putaway_qty) {
                throw new \Exception("Jumlah diterima tidak bisa di bawah yang sudah ditempatkan ke rak ({$item->putaway_qty}). Batalkan/kurangi penempatan dulu.");
            }

            $defaultBin = $this->binService->getDefaultBin($inbound->location_id);
            if (! $defaultBin) {
                throw new \Exception('Gudang ini belum memiliki Bin Inbound default.');
            }

            $this->inventoryService->adjust([
                'item_id'            => $item->item_id,
                'location_id'        => $inbound->location_id,
                'bin_id'             => $defaultBin->id,
                'qty'                => $delta,
                'transaction_number' => $inbound->transaction_number . '-EDIT-QTY',
                'source'             => $this->movementSourceFor($inbound),
                'created_by'         => "user:{$userId}",
            ]);

            $this->inboundRepository->updateItemReceivedQty($item->id, $delta);

            if ($delta < 0) {
                $this->putawayService->reduceOpenTargetForInboundItem($item->id, -$delta);
            }

            // Bump updated_version_at supaya optimistic lock berikutnya kena update.
            $inbound->forceFill(['updated_version_at' => now()])->save();

            $this->recomputeStatus($inbound->fresh('items'));

            return $this->getById($inboundId);
        });
    }

    private function recomputeStatus(Inbound $inbound): void
    {
        if ($inbound->items->isEmpty()
            || in_array($inbound->status, [Inbound::STATUS_DRAFT, Inbound::STATUS_CANCELLED], true)) {
            return;
        }

        // Fase 2: participant ACTIVE cap status ke PARTIAL max (kecuali putaway sudah jalan).
        $hasActive = InboundParticipant::where('inbound_id', $inbound->id)
            ->where('status', InboundParticipant::STATUS_ACTIVE)
            ->exists();

        $allPutaway = $inbound->items->every(fn ($i) => $i->isFullyPutaway());
        $anyPutaway = $inbound->items->contains(fn ($i) => (int) $i->putaway_qty > 0);

        if ($hasActive) {
            // Boleh naik ke PUTAWAY_IN_PROGRESS (partial putaway) tapi tidak lebih.
            $newStatus = $anyPutaway ? Inbound::STATUS_PUTAWAY_IN_PROGRESS : Inbound::STATUS_PARTIAL;
        } else {
            $newStatus = $allPutaway
                ? Inbound::STATUS_COMPLETED
                : ($anyPutaway ? Inbound::STATUS_PUTAWAY_IN_PROGRESS : Inbound::STATUS_RECEIVED);
        }

        if ($inbound->status !== $newStatus) {
            $this->inboundRepository->updateStatus($inbound, $newStatus);
        }

        // Fix C1: set once_received_at pertama kali capai RECEIVED+. Idempoten — tidak reset.
        if ($inbound->once_received_at === null
            && in_array($newStatus, [
                Inbound::STATUS_RECEIVED,
                Inbound::STATUS_PUTAWAY_IN_PROGRESS,
                Inbound::STATUS_COMPLETED,
            ], true)) {
            $inbound->forceFill(['once_received_at' => now()])->save();
        }
    }

    public function cancel(string $inboundId, ?string $userId = null): Inbound
    {
        return DB::transaction(function () use ($inboundId, $userId) {
            $inbound = $this->inboundRepository->findByIdForUpdate($inboundId);

            if (! $inbound) {
                throw new \Exception("Dokumen Inbound tidak ditemukan.");
            }

            if ($inbound->status === Inbound::STATUS_CANCELLED) {
                throw new \Exception("Inbound sudah dibatalkan.");
            }

            if ($inbound->status === Inbound::STATUS_DRAFT) {
                throw new \Exception("Inbound DRAFT tidak perlu dibatalkan.");
            }

            // F4: tolak cancel kalau ada participant mobile ACTIVE — cegah race reverse-stock vs scan realtime.
            if ($inbound->hasActiveParticipant()) {
                $active = InboundParticipant::where('inbound_id', $inbound->id)
                    ->where('status', InboundParticipant::STATUS_ACTIVE)
                    ->with('user:id,name')
                    ->get()
                    ->map(fn ($p) => [
                        'user_id' => (string) $p->user_id,
                        'name' => $p->user?->name ?? 'staff',
                    ])
                    ->toArray();
                throw new MobileSessionActiveException($active);
            }

            $inbound->loadMissing('items');

            // Guard: pastikan reverse tidak akan bikin stok bin negatif (mis. sudah dipicking/dipindah).
            // Override memory allow-negative-stock khusus flow ini — client eksplisit minta block.
            $this->assertNoStockShortfall($inbound);

            // Cascade reverse putaway kalau ada. Aman untuk semua status (skip kalau tidak ada placement).
            $hasPutaway = $inbound->items->contains(fn ($it) => (int) $it->putaway_qty > 0);
            if ($hasPutaway) {
                $this->putawayService->reverseAndDeleteForInbound($inbound->id, $userId ?? 'system');
                // reload untuk dapat putaway_qty terbaru (0) setelah reverse.
                $inbound = $this->inboundRepository->findByIdForUpdate($inboundId);
                $inbound->loadMissing('items');
            }

            if ($inbound->source_type === 'transfer' && $inbound->source_id) {
                $this->revertTransferReceipt($inbound, $userId);
                return $this->getById($inboundId);
            }

            $this->reverseReceivedStock($inbound, $userId);

            $this->inboundRepository->updateStatus($inbound, Inbound::STATUS_CANCELLED);

            if ($inbound->source_type === 'purchase_order' && $inbound->source_id) {
                $this->rollbackPurchaseOrderReceipt($inbound);
            }

            return $this->getById($inboundId);
        });
    }

    /**
     * Guard: tolak cancel kalau reverse-putaway akan bikin stok bin negatif.
     * Pesan detail per SKU/bin/qty shortfall (keputusan client 15 Jul).
     */
    private function assertNoStockShortfall(Inbound $inbound): void
    {
        $putawayIds = PutawaySource::where('inbound_id', $inbound->id)
            ->pluck('putaway_id')
            ->unique();

        if ($putawayIds->isEmpty()) {
            return;
        }

        $itemIds = PutawayItem::whereIn('putaway_id', $putawayIds)->pluck('id');
        $placements = PutawayPlacement::whereIn('putaway_item_id', $itemIds)
            ->with('putawayItem')
            ->get();

        $needed = [];
        foreach ($placements as $p) {
            $variantId = $p->putawayItem->item_id ?? null;
            $binId = $p->destination_bin_id ?? null;
            if (! $variantId || ! $binId) {
                continue;
            }
            $key = $variantId . '|' . $binId;
            $needed[$key] = ($needed[$key] ?? 0) + (int) $p->qty;
        }

        if (empty($needed)) {
            return;
        }

        $shortfalls = [];
        foreach ($needed as $key => $need) {
            [$variantId, $binId] = explode('|', $key);
            $current = (int) Inventory::where('item_id', $variantId)
                ->where('bin_id', $binId)
                ->value('on_hand');
            if ($current < $need) {
                $shortfalls[] = [
                    'variant_id' => $variantId,
                    'bin_id'     => $binId,
                    'shortfall'  => $need - $current,
                ];
            }
        }

        if (empty($shortfalls)) {
            return;
        }

        $lines = [];
        foreach ($shortfalls as $s) {
            $sku = ProductVariant::where('id', $s['variant_id'])->value('sku') ?? $s['variant_id'];
            $binCode = LocationBin::where('id', $s['bin_id'])->value('bin_final_code') ?? $s['bin_id'];
            $lines[] = "- {$sku} @ {$binCode}: minus {$s['shortfall']}";
        }

        $message = "Tidak dapat dihapus. Stok berikut akan minus:\n"
            . implode("\n", $lines)
            . "\n\nSebagian barang sudah dipicking/dipindah. Batalkan pesanan/transfer terkait dulu.";

        throw new \Exception($message);
    }

    /**
     * Rollback PO ke "Belum Diterima" (STATUS_OPEN) saat inbound PO dihapus.
     * Handle multi-inbound: kalau masih ada inbound lain aktif, recompute status dari total received tersisa.
     * Keputusan client 15 Jul: tim ops butuh proses ulang penerimaan dari awal.
     */
    private function rollbackPurchaseOrderReceipt(Inbound $inbound): void
    {
        $po = PurchaseOrder::with('items')
            ->whereKey($inbound->source_id)
            ->lockForUpdate()
            ->first();

        if (! $po) {
            return;
        }

        $otherActiveInbounds = Inbound::where('source_type', 'purchase_order')
            ->where('source_id', $po->id)
            ->where('status', '!=', Inbound::STATUS_CANCELLED)
            ->where('id', '!=', $inbound->id)
            ->with('items')
            ->get();

        // Sum received qty tersisa per variant dari inbound aktif lain
        $totalReceivedByVariant = [];
        foreach ($otherActiveInbounds as $inb) {
            foreach ($inb->items as $item) {
                $vid = $item->item_id;
                $totalReceivedByVariant[$vid] = ($totalReceivedByVariant[$vid] ?? 0) + (int) $item->received_qty;
            }
        }

        // Reset PO items received_qty sesuai sisa penerimaan aktif
        foreach ($po->items as $poItem) {
            $newReceived = min(
                (int) ($totalReceivedByVariant[$poItem->item_id] ?? 0),
                (int) $poItem->qty
            );
            $delta = $newReceived - (int) $poItem->received_qty;
            if ($delta !== 0) {
                $poItem->update(['received_qty' => $newReceived]);
            }
        }

        // Recompute PO status
        $po->load('items');
        $anyReceived = $po->items->contains(fn ($i) => (int) $i->received_qty > 0);
        $allReceived = $po->items->every(fn ($i) => (int) $i->received_qty >= (int) $i->qty);

        $newStatus = $allReceived
            ? PurchaseOrder::STATUS_FULLY_RECEIVED
            : ($anyReceived ? PurchaseOrder::STATUS_PARTIAL_RECEIVED : PurchaseOrder::STATUS_OPEN);

        if ($po->status !== $newStatus) {
            $po->update(['status' => $newStatus]);
        }
    }

    public function cancelMany(array $ids, ?string $userId = null): array
    {
        $result = ['cancelled' => [], 'failed' => []];

        foreach ($ids as $id) {
            try {
                $this->cancel($id, $userId);
                $result['cancelled'][] = $id;
            } catch (\Throwable $e) {
                $result['failed'][] = ['id' => $id, 'message' => $e->getMessage()];
            }
        }

        return $result;
    }

    private function reverseReceivedStock(Inbound $inbound, ?string $userId = null): void
    {
        $defaultBin = $this->binService->getDefaultBin($inbound->location_id);
        $createdBy = $userId ? "user:{$userId}" : 'system';

        foreach ($inbound->items as $item) {
            if ($item->received_qty <= 0) {
                continue;
            }

            $reverseQty = $item->received_qty - $item->putaway_qty;
            if ($reverseQty > 0 && $defaultBin) {
                $this->inventoryService->adjust([
                    'item_id'            => $item->item_id,
                    'location_id'        => $inbound->location_id,
                    'bin_id'             => $defaultBin->id,
                    'qty'                => -$reverseQty,
                    'transaction_number' => $inbound->transaction_number . '-CANCEL',
                    'source'             => $this->movementSourceFor($inbound),
                    'created_by'         => $createdBy,
                ]);
            }
        }
    }

    private function revertTransferReceipt(Inbound $inbound, ?string $userId = null): void
    {
        $createdBy = $userId ? "user:{$userId}" : 'system';
        $defaultBin = $this->binService->getDefaultBin($inbound->location_id);
        [$transitLocationId, $transitBinId] = $this->inventoryService->resolveTransitLocation();

        foreach ($inbound->items as $item) {
            $qty = (int) $item->received_qty;
            if ($qty <= 0) {
                continue;
            }

            if ($defaultBin) {
                $this->inventoryService->adjust([
                    'item_id'            => $item->item_id,
                    'location_id'        => $inbound->location_id,
                    'bin_id'             => $defaultBin->id,
                    'qty'                => -$qty,
                    'transaction_number' => $inbound->transaction_number . '-REVERT',
                    'source'             => 'TRANSFER_REVERT',
                    'created_by'         => $createdBy,
                ]);
            }

            $this->inventoryService->adjust([
                'item_id'            => $item->item_id,
                'location_id'        => $transitLocationId,
                'bin_id'             => $transitBinId,
                'qty'                => $qty,
                'transaction_number' => $inbound->transaction_number . '-REVERT',
                'source'             => 'TRANSFER_REVERT',
                'created_by'         => $createdBy,
            ]);

            $item->update(['received_qty' => 0]);
        }

        $this->inboundRepository->updateStatus($inbound, Inbound::STATUS_DRAFT);

        $transfer = \Modules\Inventory\Models\InventoryTransfer::whereKey($inbound->source_id)
            ->lockForUpdate()
            ->first();

        if ($transfer && $transfer->status === \Modules\Inventory\Models\InventoryTransfer::STATUS_RECEIVED) {
            $transfer->update([
                'status'         => \Modules\Inventory\Models\InventoryTransfer::STATUS_IN_TRANSIT,
                'receive_number' => null,
                'received_by'    => null,
                'received_at'    => null,
            ]);
        }
    }

    public function downloadBarcodes(string $id)
    {
        $inbound = Inbound::with(['items.variant'])->findOrFail($id);

        $pages = [];
        foreach ($inbound->items as $item) {
            $qty = $item->expected_qty > 0 ? $item->expected_qty : ($item->received_qty > 0 ? $item->received_qty : 1);

            for ($i = 0; $i < $qty; $i++) {
                $pages[] = [
                    'sku' => $item->variant->sku ?? 'UNKNOWN',
                    'name' => $item->variant->name ?? 'Unknown Product',
                ];
            }
        }

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('inbound::pdf.barcodes', compact('pages'))
            ->setPaper([0, 0, 141.73, 85.04], 'landscape');

        return $pdf->stream("barcodes-inbound-{$inbound->transaction_number}.pdf");
    }

    private function createPutawayFromInbound(Inbound $inbound, $defaultBin, string $receivedBy): void
    {
        $items = $inbound->items
            ->filter(fn ($item) => $item->received_qty > 0)
            ->map(fn ($item) => [
                'item_id'            => $item->item_id,
                'source_bin_id'      => $defaultBin->id,
                'destination_bin_id' => null,
                'qty'                => $item->received_qty,
                'batch_no'           => null,
                'serial_no'          => null,
            ])
            ->values()
            ->toArray();

        if (empty($items)) {
            return;
        }

        $this->putawayService->create([
            'location_id' => $inbound->location_id,
            'source_type' => 'INBOUND',
            'source_id'   => $inbound->id,
            'notes'       => "Auto-generated from Inbound {$inbound->transaction_number}",
            'created_by'  => $receivedBy,
            'items'       => $items,
        ]);
    }

    private function resolveLandedCostMap(Inbound $inbound): array
    {
        if ($inbound->source_type === 'sales_return') {
            return $this->resolveSalesReturnCostMap($inbound);
        }

        if ($inbound->source_type !== 'purchase_order' || empty($inbound->source_id)) {
            return [];
        }

        $items = PurchaseOrderItem::where('purchase_order_id', $inbound->source_id)->get();
        if ($items->isEmpty()) {
            return [];
        }

        $map = [];
        foreach ($items->groupBy('item_id') as $itemId => $rows) {
            $totalQty = 0.0;
            $totalCost = 0.0;
            foreach ($rows as $row) {
                $qty = (float) $row->qty;
                if ($qty <= 0) continue;
                $totalQty += $qty;
                $totalCost += $qty * (float) $row->landed_cost_per_unit;
            }
            $map[$itemId] = $totalQty > 0 ? $totalCost / $totalQty : 0;
        }

        return $map;
    }

    private function resolveSalesReturnCostMap(Inbound $inbound): array
    {
        $itemIds = $inbound->items->pluck('item_id')->filter()->unique()->values();
        if ($itemIds->isEmpty()) {
            return [];
        }

        $rows = Inventory::whereIn('item_id', $itemIds)
            ->where('avg_cost', '>', 0)
            ->get(['item_id', 'location_id', 'on_hand', 'avg_cost']);

        $map = [];
        foreach ($rows->groupBy('item_id') as $itemId => $group) {
            $preferred = $group->where('location_id', $inbound->location_id);
            $pool = $preferred->isNotEmpty() ? $preferred : $group;

            $totalQty = 0.0;
            $totalValue = 0.0;
            foreach ($pool as $row) {
                $qty = max((float) $row->on_hand, 0.0);
                $totalQty += $qty;
                $totalValue += $qty * (float) $row->avg_cost;
            }

            $map[$itemId] = $totalQty > 0
                ? $totalValue / $totalQty
                : (float) $pool->avg('avg_cost');
        }

        return $map;
    }

    private function resolveInboundPutawayStatus(Inbound $inbound): void
    {
        $allPutaway = $inbound->items->every(fn ($item) => $item->isFullyPutaway());

        // Fase 2 (§4.5): jangan promote ke COMPLETED selagi masih ada participant ACTIVE.
        $hasActive = InboundParticipant::where('inbound_id', $inbound->id)
            ->where('status', InboundParticipant::STATUS_ACTIVE)
            ->exists();

        $newStatus = ($allPutaway && ! $hasActive)
            ? Inbound::STATUS_COMPLETED
            : Inbound::STATUS_PUTAWAY_IN_PROGRESS;

        $this->inboundRepository->updateStatus($inbound, $newStatus);
    }
}
