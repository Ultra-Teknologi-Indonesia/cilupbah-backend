<?php

namespace Modules\Inbound\Services;

use App\Enums\AssignmentActionEnum;
use App\Enums\ClientChannelEnum;
use App\Enums\UnassignReasonEnum;
use App\Exceptions\AssignmentLockException;
use App\Exceptions\InboundSessionClosedException;
use App\Exceptions\MobileSessionActiveException;
use App\Exceptions\PutawayActiveException;
use App\Exceptions\UserFacingException;
use App\Models\AssignmentHistory;
use App\Models\User;
use App\Support\ActorName;
use App\Support\WarehouseAccess;
use App\Traits\AutoScopeMobileToAuth;
use App\Traits\EnforcesAssignmentChannel;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Inbound\Models\Inbound;
use Modules\Inbound\Models\InboundAssignment;
use Modules\Inbound\Models\InboundItem;
use Modules\Inbound\Models\InboundParticipant;
use Modules\Inbound\Models\InboundReceipt;
use Modules\Inbound\Repositories\InboundRepository;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Inventory\Models\InventoryTransfer;
use Modules\Inventory\Models\Putaway;
use Modules\Inventory\Models\PutawayItem;
use Modules\Inventory\Models\PutawayItemSource;
use Modules\Inventory\Models\PutawayPlacement;
use Modules\Inventory\Models\PutawaySource;
use Modules\Inventory\Repositories\InventoryRepository;
use Modules\Inventory\Services\InventoryService;
use Modules\Inventory\Services\InventoryMovementReversalVisibilityService;
use Modules\Inventory\Services\PurchaseCostService;
use Modules\Inventory\Services\PutawayService;
use Modules\Notification\Events\TaskAssigned;
use Modules\Notification\Services\NotificationDispatcher;
use Modules\Product\Models\ProductVariant;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Models\PurchaseOrderItem;
use Modules\Purchase\Repositories\PurchaseOrderRepository;
use Modules\Warehouse\Models\LocationBin;
use Modules\Warehouse\Services\LocationBinService;

class InboundService
{
    use AutoScopeMobileToAuth;
    use EnforcesAssignmentChannel;

    public const NOTIF_PENEMPATAN = 'view-penempatan';

    public function __construct(
        protected InboundRepository $inboundRepository,
        protected InventoryService $inventoryService,
        protected LocationBinService $binService,
        protected PutawayService $putawayService,
        protected NotificationDispatcher $notifications,
        protected InventoryRepository $inventoryRepository,
        protected PurchaseOrderRepository $purchaseOrderRepository,
        protected InventoryMovementReversalVisibilityService $movementReversalVisibility,
    ) {}

    protected function unlockedOnceColumn(Model $doc): string
    {
        return 'once_received_at';
    }

    protected function assertWebCanMutate(Model $doc): void
    {
        $doc->refresh();

        if ($this->isCancelled($doc)) {
            return;
        }

        if (! $this->isUnlockedByCompletion($doc)) {

            if (! $doc instanceof Inbound || ! $doc->hasActiveParticipant()) {
                return;
            }
        }

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

    protected function assertMobileCanMutate(Model $doc, string $actorId): void
    {
        $doc->refresh();

        if (! $doc instanceof Inbound) {

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

        if ($doc->isSessionClosed()) {
            throw new InboundSessionClosedException($doc->transaction_number);
        }

        $existing = InboundParticipant::where('inbound_id', $doc->id)
            ->where('user_id', $actorId)
            ->first();

        if ($existing) {
            if ($existing->status === InboundParticipant::STATUS_WITHDRAWN) {

                throw new UserFacingException(
                    title: 'Anda ditarik dari sesi ini',
                    message: 'Anda ditarik dari sesi penerimaan ini oleh admin. Hubungi admin untuk bergabung ulang.',
                    status: 403,
                    errors: ['code' => 'PARTICIPANT_WITHDRAWN'],
                );
            }
            if ($existing->status === InboundParticipant::STATUS_DONE) {

                $existing->update([
                    'status' => InboundParticipant::STATUS_ACTIVE,
                    'completed_at' => null,
                ]);
            }

            return;
        }

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

    public function getStatusCounts(?string $type = null, ?string $locationId = null): array
    {
        $query = Inbound::query();
        if ($type !== null && $type !== '') {
            $query->where('type', $type);
        }
        if ($locationId !== null && $locationId !== '') {
            $query->where('location_id', $locationId);
        }

        WarehouseAccess::apply($query, 'location_id');

        $rows = $query
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->toArray();

        $statuses = [
            Inbound::STATUS_DRAFT,
            Inbound::STATUS_PARTIAL,
            Inbound::STATUS_RECEIVED,
            Inbound::STATUS_PUTAWAY_IN_PROGRESS,
            Inbound::STATUS_COMPLETED,
            Inbound::STATUS_CANCELLED,
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

    public function getPaginatedItems(string $inboundId, int $perPage = 10)
    {
        return $this->inboundRepository->getPaginatedItems($inboundId, $perPage);
    }

    public function getItemTotals(string $inboundId): array
    {
        return $this->inboundRepository->getItemTotals($inboundId);
    }

    public function getItemsPayload(string $inboundId, int $perPage = 10): array
    {
        $items = $this->getPaginatedItems($inboundId, $perPage);

        return [
            'items' => $items->items(),
            'meta' => [
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
                'totals' => $this->getItemTotals($inboundId),
            ],
        ];
    }

    public function resolveReceivedByActor(Request $request, ?string $webValue): string
    {
        return $this->overrideForMobile($request, $webValue)
            ?? $webValue
            ?? (string) (auth()->id() ?? 'system');
    }

    public function createAdditionalDraftFromPurchaseOrderId(string $poId, string $createdBy): ?Inbound
    {
        $po = $this->purchaseOrderRepository->findById($poId);

        if (! $po) {
            return null;
        }

        return $this->createDraftFromPO($po, $createdBy, true);
    }

    public function getReceiptsPaginated(string $inboundId, int $perPage = 50)
    {
        return $this->inboundRepository->getReceiptsPaginated($inboundId, $perPage);
    }

    public function getById(string $id): ?Inbound
    {
        return $this->inboundRepository->findById($id);
    }

    public function getDetailPayload(string $id): ?array
    {
        $inbound = $this->getById($id);
        if (! $inbound) {
            return null;
        }

        $inbound->load(['participants.user:id,name']);

        $receiptAgg = InboundReceipt::query()
            ->join('inbound_items as i', 'inbound_receipts.inbound_item_id', '=', 'i.id')
            ->where('i.inbound_id', $inbound->id)
            ->groupBy('inbound_receipts.received_by_user_id')
            ->selectRaw('inbound_receipts.received_by_user_id as user_id')
            ->selectRaw('COUNT(*) as cnt')
            ->selectRaw('COALESCE(SUM(inbound_receipts.qty),0) as qty_sum')
            ->get()
            ->keyBy('user_id');

        $participantsData = $inbound->participants->map(function ($p) use ($receiptAgg) {
            $agg = $receiptAgg->get($p->user_id);

            return [
                'id' => $p->id,
                'user_id' => $p->user_id,
                'name' => $p->user?->name ?? 'staff',
                'role' => $p->role,
                'status' => $p->status,
                'joined_at' => $p->joined_at?->toIso8601String(),
                'completed_at' => $p->completed_at?->toIso8601String(),
                'withdrawn_at' => $p->withdrawn_at?->toIso8601String(),
                'withdraw_reason' => $p->withdraw_reason,
                'receipts_count' => (int) ($agg->cnt ?? 0),
                'receipts_qty_sum' => (int) ($agg->qty_sum ?? 0),
            ];
        })->values();

        $activeParticipants = $participantsData
            ->where('status', InboundParticipant::STATUS_ACTIVE)
            ->values();

        $editLock = [
            'locked' => $activeParticipants->isNotEmpty(),
            'reason' => $activeParticipants->isNotEmpty() ? 'mobile_session_active' : null,
            'active_participants' => $activeParticipants
                ->map(fn ($p) => ['user_id' => $p['user_id'], 'name' => $p['name']])
                ->values(),
        ];

        $data = $inbound->toArray();
        $data['participants'] = $participantsData;
        $data['edit_lock'] = $editLock;

        return $data;
    }

    private function movementSourceFor(Inbound $inbound): string
    {
        return match ($inbound->type) {
            Inbound::TYPE_PURCHASE_ORDER => 'PURCHASE',
            Inbound::TYPE_SALES_RETURN => 'SALES_RETURN',
            Inbound::TYPE_TRANSIT_IN => 'TRANSFER_IN',
            Inbound::TYPE_CONSIGNMENT => 'CONSIGNMENT',
            default => 'ADJUSTMENT',
        };
    }

    public function createDraft(array $data): Inbound
    {
        return DB::transaction(function () use ($data) {
            $data['transaction_number'] = $data['transaction_number'] ?? 'INB-'.Str::upper(Str::random(8));
            $data['status'] = Inbound::STATUS_DRAFT;

            $inbound = $this->inboundRepository->create($data);

            foreach ($data['items'] as $itemData) {
                $itemData['inbound_id'] = $inbound->id;
                $this->inboundRepository->createItem($itemData);
            }

            return $inbound->load('items');
        });
    }

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
                        .'Pastikan items sudah persist (DB::afterCommit) sebelum panggil createDraftFromPO().',
                    status: 500,
                    errors: ['code' => 'PO_ITEMS_EMPTY'],
                );
            }

            do {
                $candidate = 'INB-'.Str::upper(Str::random(8));
            } while (Inbound::where('transaction_number', $candidate)->exists());

            $poNumber = $po->po_number ?? $po->purchase_order_number ?? '';
            $inboundNotes = $po->notes ? trim($po->notes) : null;
            if ($isAdditional && ! $inboundNotes) {
                $inboundNotes = "Penerimaan susulan PO {$poNumber}";
            }

            $inbound = $this->inboundRepository->create([
                'location_id' => $po->location_id,
                'transaction_number' => $candidate,
                'reference_number' => $poNumber ?: null,
                'type' => Inbound::TYPE_PURCHASE_ORDER,
                'source_type' => 'purchase_order',
                'source_id' => $po->id,
                'status' => Inbound::STATUS_DRAFT,
                'expected_date' => now(),
                'created_by' => $createdBy,
                'notes' => $inboundNotes,
            ]);

            foreach ($po->items as $poItem) {
                if ($isAdditional) {
                    $remaining = max(0, (int) $poItem->qty - (int) $poItem->received_qty);
                    if ($remaining <= 0) {
                        continue;
                    }
                    $expectedQty = $remaining;
                } else {
                    $expectedQty = (int) $poItem->qty;
                }

                $this->inboundRepository->createItem([
                    'inbound_id' => $inbound->id,
                    'item_id' => $poItem->item_id,
                    'expected_qty' => $expectedQty,
                    'received_qty' => 0,
                ]);
            }

            return $inbound->load('items');
        });
    }

    public function findDraftByPO(string $poId): ?Inbound
    {
        return Inbound::where('source_type', 'purchase_order')
            ->where('source_id', $poId)
            ->where('status', '!=', Inbound::STATUS_CANCELLED)
            ->first();
    }

    public function assertPurchaseOrderEditable(PurchaseOrder $po): void
    {
        $inbound = $this->findDraftByPO($po->id);

        if ($inbound) {
            $this->assertWebCanMutate($inbound);
        }
    }

    public function resyncDraftFromPO(PurchaseOrder $po): void
    {
        $inbound = $this->findDraftByPO($po->id);

        if (! $inbound) {
            return;
        }

        DB::transaction(function () use ($po, $inbound) {
            $po->loadMissing('items');

            $poQtyByItem = $po->items
                ->groupBy('item_id')
                ->map(fn ($rows) => (int) $rows->sum('qty'));

            $existing = InboundItem::where('inbound_id', $inbound->id)
                ->lockForUpdate()
                ->get()
                ->keyBy('item_id');

            foreach ($poQtyByItem as $itemId => $qty) {
                $row = $existing->get($itemId);

                if ($row) {
                    if ((int) $row->expected_qty !== $qty) {
                        $row->update(['expected_qty' => $qty]);
                    }

                    continue;
                }

                $this->inboundRepository->createItem([
                    'inbound_id' => $inbound->id,
                    'item_id' => $itemId,
                    'expected_qty' => $qty,
                    'received_qty' => 0,
                ]);
            }

            $orphanIds = $existing->keys()
                ->diff($poQtyByItem->keys())
                ->map(fn ($itemId) => $existing->get($itemId)->id);

            $this->deleteUntouchedInboundItems($inbound, $orphanIds);

            $inbound->forceFill(['updated_version_at' => now()])->save();
        });
    }

    protected function deleteUntouchedInboundItems(Inbound $inbound, $orphanIds): void
    {
        $orphanIds = collect($orphanIds);

        if ($orphanIds->isEmpty()) {
            return;
        }

        $deletableIds = InboundItem::whereIn('id', $orphanIds)
            ->where('received_qty', 0)
            ->where('rejected_qty', 0)
            ->where('putaway_qty', 0)
            ->where('reserved_qty', 0)
            ->pluck('id')
            ->diff(
                DB::table('inbound_receipts')
                    ->whereIn('inbound_item_id', $orphanIds)
                    ->pluck('inbound_item_id')
                    ->merge(
                        DB::table('putaway_item_sources')
                            ->whereIn('inbound_item_id', $orphanIds)
                            ->pluck('inbound_item_id')
                    )
            );

        if ($deletableIds->isNotEmpty()) {
            InboundItem::whereIn('id', $deletableIds)->delete();
        }

        $keptIds = $orphanIds->diff($deletableIds);

        if ($keptIds->isNotEmpty()) {
            Log::warning('resyncDraftFromPO: baris inbound dipertahankan karena sudah tersentuh penerimaan/putaway', [
                'inbound_id' => $inbound->id,
                'inbound_item_ids' => $keptIds->values()->all(),
            ]);
        }
    }

    private function purchaseInboundItemsForVariant(PurchaseOrder $po, string $variantId)
    {
        return InboundItem::query()
            ->join('inbounds', 'inbounds.id', '=', 'inbound_items.inbound_id')
            ->where('inbounds.source_type', 'purchase_order')
            ->where('inbounds.source_id', $po->id)
            ->where('inbounds.status', '!=', Inbound::STATUS_CANCELLED)
            ->where('inbound_items.item_id', $variantId)
            ->where('inbound_items.received_qty', '>', 0)
            ->orderByDesc('inbounds.created_at')
            ->select('inbound_items.*')
            ->lockForUpdate()
            ->get();
    }

    private function placementsForInboundItem(InboundItem $item)
    {
        return PutawayItemSource::query()
            ->join('putaway_items', 'putaway_items.id', '=', 'putaway_item_sources.putaway_item_id')
            ->join('putaway_placements', 'putaway_placements.putaway_item_id', '=', 'putaway_items.id')
            ->where('putaway_item_sources.inbound_item_id', $item->id)
            ->where('putaway_placements.qty', '>', 0)
            ->orderByDesc('putaway_placements.created_at')
            ->select(
                'putaway_placements.id as placement_id',
                'putaway_placements.qty as placement_qty',
                'putaway_placements.bin_id as bin_id',
                'putaway_items.id as putaway_item_id',
                'putaway_items.putaway_id as putaway_id',
            )
            ->get();
    }

    public function assertPurchaseReversalPossible(PurchaseOrder $po, array $qtyByVariant): void
    {
        $needed = [];

        foreach ($qtyByVariant as $variantId => $qtyToReverse) {
            $remaining = (int) $qtyToReverse;

            foreach ($this->purchaseInboundItemsForVariant($po, $variantId) as $item) {
                if ($remaining <= 0) {
                    break;
                }

                $fromThisItem = min($remaining, (int) $item->received_qty);
                $remaining -= $fromThisItem;
                $fromPutaway = min($fromThisItem, (int) $item->putaway_qty);

                foreach ($this->placementsForInboundItem($item) as $placement) {
                    if ($fromPutaway <= 0) {
                        break;
                    }
                    $take = min($fromPutaway, (int) $placement->placement_qty);
                    $fromPutaway -= $take;

                    $key = $variantId.'|'.$placement->bin_id;
                    $needed[$key] = ($needed[$key] ?? 0) + $take;
                }
            }
        }

        $shortfalls = [];
        foreach ($needed as $key => $need) {
            [$variantId, $binId] = explode('|', $key);
            $onHand = (int) Inventory::where('item_id', $variantId)
                ->where('bin_id', $binId)
                ->value('on_hand');

            if ($onHand < $need) {
                $sku = ProductVariant::where('id', $variantId)->value('sku') ?? $variantId;
                $binCode = LocationBin::where('id', $binId)->value('bin_final_code') ?? $binId;
                $shortfalls[] = "- {$sku} @ {$binCode}: butuh {$need}, tersedia {$onHand} (kurang ".($need - $onHand).')';
            }
        }

        if (empty($shortfalls)) {
            return;
        }

        throw ValidationException::withMessages([
            'items' => [
                "Perubahan ini perlu menarik balik stok yang sudah tidak ada di rak:\n"
                .implode("\n", $shortfalls)
                ."\n\nBarangnya sudah dipicking/dipindah/terjual. Batalkan pesanan atau transfer terkait dulu.",
            ],
        ]);
    }

    public function reversePurchaseReceiptForVariant(
        PurchaseOrder $po,
        string $variantId,
        int $qtyToReverse,
        ?string $userId = null,
    ): void {
        if ($qtyToReverse <= 0) {
            return;
        }

        $createdBy = $userId ? "user:{$userId}" : 'system';
        $remaining = $qtyToReverse;

        foreach ($this->purchaseInboundItemsForVariant($po, $variantId) as $item) {
            if ($remaining <= 0) {
                break;
            }

            $fromThisItem = min($remaining, (int) $item->received_qty);
            $remaining -= $fromThisItem;

            $fromPutaway = min($fromThisItem, (int) $item->putaway_qty);

            foreach ($this->placementsForInboundItem($item) as $placement) {
                if ($fromPutaway <= 0) {
                    break;
                }

                $take = min($fromPutaway, (int) $placement->placement_qty);
                $fromPutaway -= $take;

                $this->putawayService->deletePlacements(
                    $placement->putaway_id,
                    [[
                        'item_id' => $placement->putaway_item_id,
                        'placement_id' => $placement->placement_id,
                        'qty' => $take,
                    ]],
                    $userId ?? 'system',
                );
            }

            $item->refresh();

            $notPutAway = $fromThisItem - min($fromThisItem, (int) $item->putaway_qty);
            $inbound = Inbound::find($item->inbound_id);
            $defaultBin = $inbound ? $this->binService->getDefaultBin($inbound->location_id) : null;

            if ($notPutAway > 0 && $defaultBin && $inbound) {
                $this->inventoryService->adjustInboundStaging([
                    'item_id' => $item->item_id,
                    'location_id' => $inbound->location_id,
                    'bin_id' => $defaultBin->id,
                    'qty' => -$notPutAway,
                    'transaction_number' => $inbound->transaction_number.'-KOREKSI-PO',
                    'source' => 'PURCHASE_REVERSAL',
                    'created_by' => $createdBy,
                ]);
            }

            $item->update([
                'received_qty' => max(0, (int) $item->received_qty - $fromThisItem),
                'expected_qty' => max(0, (int) $item->expected_qty - $fromThisItem),
            ]);

            if ($inbound) {
                $inbound->forceFill(['updated_version_at' => now()])->save();
            }
        }
    }

    public function receiveFromPO(array $data): Inbound
    {
        $data['type'] = Inbound::TYPE_PURCHASE_ORDER;
        $data['source_type'] = 'purchase_order';

        return DB::transaction(function () use ($data) {
            $items = $data['items'] ?? [];
            unset($data['items']);

            do {
                $candidate = 'INB-'.Str::upper(Str::random(8));
            } while (Inbound::where('transaction_number', $candidate)->exists());
            $data['transaction_number'] = $candidate;
            $data['status'] = Inbound::STATUS_DRAFT;

            $inbound = $this->inboundRepository->create($data);

            $receiveItems = [];
            foreach ($items as $itemData) {
                $acceptedQty = $itemData['accepted_qty'] ?? $itemData['expected_qty'];
                $rejectedQty = $itemData['rejected_qty'] ?? 0;

                $inboundItem = $this->inboundRepository->createItem([
                    'inbound_id' => $inbound->id,
                    'item_id' => $itemData['item_id'],
                    'expected_qty' => $itemData['expected_qty'],
                    'received_qty' => 0,
                    'rejected_qty' => $rejectedQty,
                    'rejection_note' => $itemData['rejection_note'] ?? null,
                    'condition' => $rejectedQty > 0 ? 'PARTIAL' : 'GOOD',
                    'notes' => $itemData['notes'] ?? null,
                ]);

                if ($acceptedQty > 0) {
                    $receiveItems[] = [
                        'inbound_item_id' => $inboundItem->id,
                        'qty' => $acceptedQty,
                        'condition' => 'GOOD',
                    ];
                }
            }

            if (empty($receiveItems)) {
                $inbound->update(['status' => Inbound::STATUS_RECEIVED]);

                return $inbound->load('items');
            }

            return $this->receive($inbound->id, [
                'received_by' => $data['created_by'],
                'idempotency_key' => "purchase-auto-receive:{$inbound->id}",
                'items' => $receiveItems,
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
                    'inbound_id' => $inbound->id,
                    'item_id' => $itemData['item_id'],
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
                'location_id' => $data['location_id'],
                'transaction_number' => $data['transaction_number'] ?? app(InventoryService::class)->generateTrfiNumber(),
                'reference_number' => $data['reference_number'] ?? null,
                'type' => Inbound::TYPE_TRANSIT_IN,
                'source_type' => 'transfer',
                'source_id' => $data['source_id'],
                'status' => Inbound::STATUS_DRAFT,
                'expected_date' => $data['expected_date'] ?? now(),
                'created_by' => $data['created_by'],
            ]);

            foreach ($data['items'] as $itemData) {
                $this->inboundRepository->createItem([
                    'inbound_id' => $inbound->id,
                    'item_id' => $itemData['item_id'],
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

                'status' => $anyShortfall ? Inbound::STATUS_PARTIAL : Inbound::STATUS_RECEIVED,
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
        ], locationId: $inbound->location_id);

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
                throw new UserFacingException(title: 'Tidak ditemukan', message: 'Dokumen Inbound tidak ditemukan.', status: 404);
            }

            $idempotencyKey = trim((string) ($data['idempotency_key'] ?? ''));
            if (mb_strlen($idempotencyKey) > 100) {
                throw new UserFacingException(
                    title: 'Kunci transaksi tidak valid',
                    message: 'Idempotency-Key maksimal 100 karakter.',
                    status: 422,
                    errors: ['code' => 'INVALID_IDEMPOTENCY_KEY'],
                );
            }

            $receivedByUserId = auth()->id();
            if (! $receivedByUserId) {
                $candidate = (string) ($data['received_by'] ?? '');
                if (Str::isUuid($candidate)) {
                    $receivedByUserId = $candidate;
                }
            }

            if ($this->currentChannel() === ClientChannelEnum::MOBILE && $idempotencyKey === '') {
                throw new UserFacingException(
                    title: 'Aplikasi perlu diperbarui',
                    message: 'Penerimaan dari aplikasi mobile wajib memiliki kunci transaksi agar retry jaringan tidak menggandakan stok.',
                    status: 422,
                    errors: ['code' => 'INBOUND_RECEIPT_IDEMPOTENCY_KEY_REQUIRED'],
                );
            }

            if ($idempotencyKey !== '' && $this->isReceiptRequestReplay(
                $inbound,
                $data['items'],
                $idempotencyKey,
                $receivedByUserId,
            )) {
                return $this->getById($inboundId);
            }

            if ($this->currentChannel() === ClientChannelEnum::MOBILE) {
                $this->assertMobileCanMutate($inbound, (string) $data['received_by']);
            } else {
                $this->assertWebCanMutate($inbound);
            }

            if (! $inbound->isReceivable()) {
                throw new UserFacingException(title: 'Status tidak sesuai', message: "Inbound sudah berstatus {$inbound->status}, tidak bisa menerima barang.", status: 409);
            }

            $defaultBin = $this->binService->getDefaultBin($inbound->location_id);
            if (! $defaultBin) {
                throw new UserFacingException(title: 'Aksi tidak dapat diproses', message: 'Gudang ini belum memiliki Bin Inbound default.', status: 422);
            }

            $itemsDict = $inbound->items->keyBy('id');

            $landedCostMap = $this->resolveLandedCostMap($inbound);

            foreach ($data['items'] as $receiptData) {
                $inboundItem = $itemsDict->get($receiptData['inbound_item_id']);
                if (! $inboundItem) {
                    throw new UserFacingException(title: 'Aksi tidak dapat diproses', message: "Item ID {$receiptData['inbound_item_id']} tidak terkait dengan Inbound ini.", status: 422);
                }

                $condition = $receiptData['condition'] ?? 'GOOD';
                $isDamage = $condition === 'DAMAGE';

                $receipt = $this->inboundRepository->createReceipt([
                    'inbound_item_id' => $inboundItem->id,
                    'idempotency_key' => $idempotencyKey !== '' ? $idempotencyKey : null,
                    'qty' => $receiptData['qty'],
                    'bin_id' => $defaultBin->id,
                    'batch_no' => $receiptData['batch_no'] ?? null,
                    'serial_no' => $receiptData['serial_no'] ?? null,
                    'condition' => $condition,
                    'received_by_user_id' => $receivedByUserId,
                    'received_date' => now(),
                ]);

                if ($isDamage) {
                    $this->inboundRepository->updateItemRejectedQty($inboundItem->id, $receiptData['qty']);
                    $inboundItem->rejected_qty = ($inboundItem->rejected_qty ?? 0) + $receiptData['qty'];
                } else {
                    $this->inboundRepository->updateItemReceivedQty($inboundItem->id, $receiptData['qty']);
                    $inboundItem->received_qty += $receiptData['qty'];
                }

                if (! $isDamage) {
                    $this->inventoryService->adjustInboundStaging([
                        'item_id' => $inboundItem->item_id,
                        'location_id' => $inbound->location_id,
                        'bin_id' => $defaultBin->id,
                        'inbound_receipt_id' => $receipt->id,
                        'batch_no' => $receiptData['batch_no'] ?? '',
                        'serial_no' => $receiptData['serial_no'] ?? '',
                        'qty' => $receiptData['qty'],
                        'transaction_number' => $inbound->transaction_number,
                        'source' => $this->movementSourceFor($inbound),
                        'created_by' => $data['received_by'],
                    ]);
                }

                $landedCost = (float) ($landedCostMap[$inboundItem->item_id] ?? 0);
                if ($landedCost > 0 && ! $isDamage) {
                    InventoryMovement::where('inbound_receipt_id', $receipt->id)
                        ->whereNull('cost_per_unit')
                        ->limit(1)
                        ->update([
                            'cost_per_unit' => $landedCost,
                            'total_cost' => round($landedCost * (float) $receiptData['qty'], 2),
                        ]);

                    $this->inventoryService->recalculateAverageCost(
                        $inboundItem->item_id,
                        $inbound->location_id,
                        $defaultBin->id,
                        (float) $receiptData['qty'],
                        $landedCost,
                        $receiptData['batch_no'] ?? '',
                        $receiptData['serial_no'] ?? '',
                    );
                }
            }

            $this->inboundRepository->updateStatus($inbound, Inbound::STATUS_PARTIAL);
            $inbound->forceFill(['updated_version_at' => now()])->save();

            return $this->getById($inboundId);
        });

        return $result;
    }

    private function isReceiptRequestReplay(
        Inbound $inbound,
        array $items,
        string $idempotencyKey,
        ?string $receivedByUserId,
    ): bool {
        $itemIds = collect($items)->pluck('inbound_item_id')->values();
        $ownedItemIds = $inbound->items->pluck('id');

        if ($itemIds->diff($ownedItemIds)->isNotEmpty()) {
            throw new UserFacingException(
                title: 'Aksi tidak dapat diproses',
                message: 'Ada item yang tidak terkait dengan dokumen inbound ini.',
                status: 422,
                errors: ['code' => 'INBOUND_ITEM_DOCUMENT_MISMATCH'],
            );
        }

        $existing = InboundReceipt::query()
            ->whereIn('inbound_item_id', $itemIds)
            ->where('idempotency_key', $idempotencyKey)
            ->lockForUpdate()
            ->get()
            ->keyBy('inbound_item_id');

        if ($existing->isEmpty()) {
            return false;
        }

        if ($existing->count() !== $itemIds->count()) {
            throw new UserFacingException(
                title: 'Penerimaan perlu diperiksa',
                message: 'Permintaan ulang hanya tercatat sebagian. Data tidak ditambah agar stok tidak menjadi ganda.',
                status: 409,
                errors: ['code' => 'INBOUND_RECEIPT_IDEMPOTENCY_PARTIAL'],
            );
        }

        foreach ($items as $item) {
            $receipt = $existing->get($item['inbound_item_id']);
            $matches = $receipt
                && (int) $receipt->qty === (int) $item['qty']
                && (string) $receipt->condition === (string) ($item['condition'] ?? 'GOOD')
                && (string) ($receipt->batch_no ?? '') === (string) ($item['batch_no'] ?? '')
                && (string) ($receipt->serial_no ?? '') === (string) ($item['serial_no'] ?? '')
                && (string) ($receipt->received_by_user_id ?? '') === (string) ($receivedByUserId ?? '');

            if (! $matches) {
                throw new UserFacingException(
                    title: 'Permintaan penerimaan tidak konsisten',
                    message: 'Kunci transaksi ini sudah pernah digunakan dengan jumlah atau barang yang berbeda.',
                    status: 409,
                    errors: ['code' => 'INBOUND_RECEIPT_IDEMPOTENCY_CONFLICT'],
                );
            }
        }

        return true;
    }

    public function closeReceiving(string $inboundId, string $closedBy): Inbound
    {
        $result = DB::transaction(function () use ($inboundId, $closedBy) {
            $inbound = $this->inboundRepository->findByIdForUpdate($inboundId);

            if (! $inbound) {
                throw new UserFacingException(title: 'Tidak ditemukan', message: 'Dokumen Inbound tidak ditemukan.', status: 404);
            }

            $closeable = in_array($inbound->status, [
                Inbound::STATUS_DRAFT,
                Inbound::STATUS_PARTIAL,
                Inbound::STATUS_RECEIVED,
                Inbound::STATUS_COMPLETED,
            ]);

            if (! $closeable) {
                throw new UserFacingException(title: 'Status tidak sesuai', message: "Inbound sudah berstatus {$inbound->status}.", status: 409);
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

            InboundParticipant::where('inbound_id', $inbound->id)
                ->where('status', InboundParticipant::STATUS_ACTIVE)
                ->update([
                    'status' => InboundParticipant::STATUS_WITHDRAWN,
                    'withdrawn_by' => $closedBy,
                    'withdraw_reason' => 'admin_finalize',
                    'withdrawn_at' => now(),
                ]);

            $this->inboundRepository->updateStatus($inbound, Inbound::STATUS_RECEIVED);
            $fill = ['updated_version_at' => now()];
            if ($inbound->once_received_at === null) {
                $fill['once_received_at'] = now();
            }
            $inbound->forceFill($fill)->save();

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
            ], excludeUserIds: array_filter([$closedBy]), locationId: $result->location_id);
        }

        return $result;
    }

    public function processPutaway(string $inboundId, array $data): Inbound
    {
        throw new UserFacingException(
            title: 'Gunakan dokumen penempatan',
            message: 'Jalur penempatan lama dinonaktifkan karena tidak membentuk kronologi dan counter sumber secara lengkap.',
            status: 409,
            errors: ['code' => 'LEGACY_PUTAWAY_DISABLED'],
        );
    }

    public function autoPutaway(string $inboundId, string $createdBy): Inbound
    {
        throw new UserFacingException(
            title: 'Gunakan dokumen penempatan',
            message: 'Auto-putaway lama dinonaktifkan. Buat dokumen penempatan agar sumber, progres, dan kronologi selalu tercatat bersama.',
            status: 409,
            errors: ['code' => 'LEGACY_PUTAWAY_DISABLED'],
        );
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
                throw new UserFacingException(title: 'Tidak ditemukan', message: 'Dokumen Inbound tidak ditemukan.', status: 404);
            }

            if ($inbound->status === Inbound::STATUS_CANCELLED) {
                throw new UserFacingException(title: 'Status tidak sesuai', message: "Inbound berstatus {$inbound->status}, tidak bisa di-assign.", status: 409);
            }

            $previousAssignee = $inbound->assigned_to;
            $action = $previousAssignee === null
                ? AssignmentActionEnum::ASSIGN
                : AssignmentActionEnum::REASSIGN;

            $assignment = $this->inboundRepository->createAssignment([
                'inbound_id' => $inboundId,
                'assigned_to' => $assignedTo,
                'assigned_by' => $assignedBy,
                'status' => InboundAssignment::STATUS_PENDING,
                'notes' => $notes,
            ]);

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

            return $assignment->load('worker:id,name,email', 'assigner:id,name,email');
        });
    }

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
                throw new UserFacingException(title: 'Tidak ditemukan', message: 'Dokumen Inbound tidak ditemukan.', status: 404);
            }

            $previousAssignee = $inbound->assigned_to;
            $isSelf = $previousAssignee !== null && (string) $previousAssignee === $actorId;
            $action = $isSelf ? AssignmentActionEnum::SELF_UNASSIGN : AssignmentActionEnum::UNASSIGN;

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
                    'inbound_id' => $inboundId,
                    'assigned_to' => $newAssigneeId,
                    'assigned_by' => $actorId,
                    'status' => InboundAssignment::STATUS_PENDING,
                    'notes' => "Handover: {$reason->label()}",
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

    public function resetAssignment(
        string $inboundId,
        string $actorId,
        string $reasonNote,
        ?string $newAssigneeId = null,
    ): Inbound {
        return DB::transaction(function () use ($inboundId, $actorId, $reasonNote, $newAssigneeId) {
            $inbound = $this->inboundRepository->findByIdForUpdate($inboundId);
            if (! $inbound) {
                throw new UserFacingException(title: 'Tidak ditemukan', message: 'Dokumen Inbound tidak ditemukan.', status: 404);
            }

            if ($inbound->assigned_to !== null
                && $inbound->status === Inbound::STATUS_PARTIAL) {
                throw new AssignmentLockException(
                    assignedToName: $this->resolveUserName($inbound->assigned_to),
                    assignedToId: (string) $inbound->assigned_to,
                    assignedAt: $inbound->assigned_at?->toDateTimeString(),
                );
            }

            $activePutaways = Putaway::whereHas('sources', fn ($q) => $q->where('inbound_id', $inboundId))
                ->whereIn('status', [Putaway::STATUS_NOT_STARTED, Putaway::STATUS_IN_PROGRESS])
                ->pluck('putaway_no')
                ->toArray();

            if (! empty($activePutaways)) {
                throw new PutawayActiveException($activePutaways);
            }

            $defaultBin = $this->binService->getDefaultBin($inbound->location_id);
            if (! $defaultBin) {
                throw new UserFacingException(title: 'Aksi tidak dapat diproses', message: 'Gudang ini belum memiliki Bin Inbound default.', status: 422);
            }

            $previousAssignee = $inbound->assigned_to;

            $inbound->load('items');
            foreach ($inbound->items as $item) {
                $received = (int) $item->received_qty;
                if ($received <= 0) {
                    continue;
                }

                $this->inventoryService->adjustInboundStaging([
                    'item_id' => $item->item_id,
                    'location_id' => $inbound->location_id,
                    'bin_id' => $defaultBin->id,
                    'qty' => -$received,
                    'transaction_number' => $inbound->transaction_number.'-RESET',
                    'source' => $this->movementSourceFor($inbound),
                    'created_by' => "user:{$actorId}",
                ]);

                $this->inboundRepository->updateItemReceivedQty($item->id, -$received);
            }

            $inbound->forceFill([
                'assigned_to' => $newAssigneeId,
                'assigned_by' => $newAssigneeId ? $actorId : null,
                'assigned_at' => $newAssigneeId ? now() : null,
                'status' => Inbound::STATUS_DRAFT,
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

    public function joinSession(string $inboundId, string $userId): Inbound
    {
        return DB::transaction(function () use ($inboundId, $userId) {
            $inbound = $this->inboundRepository->findByIdForUpdate($inboundId);
            if (! $inbound) {
                throw new UserFacingException(title: 'Tidak ditemukan', message: 'Dokumen Inbound tidak ditemukan.', status: 404);
            }

            $this->assertMobileCanMutate($inbound, $userId);

            return $inbound->fresh('participants');
        });
    }

    public function withdrawParticipant(
        string $inboundId,
        string $targetUserId,
        string $actorId,
        ?string $reasonNote = null,
    ): Inbound {
        return DB::transaction(function () use ($inboundId, $targetUserId, $actorId, $reasonNote) {
            $inbound = $this->inboundRepository->findByIdForUpdate($inboundId);
            if (! $inbound) {
                throw new UserFacingException(title: 'Tidak ditemukan', message: 'Dokumen Inbound tidak ditemukan.', status: 404);
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
                    message: 'Peserta sudah tidak aktif (status: '.$participant->status.').',
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
            'subject_id' => $inbound->id,
            'from_user_id' => $fromUserId,
            'to_user_id' => $toUserId,
            'actor_id' => $actorId,
            'action' => $action->value,
            'channel' => $this->currentChannel()->value,
            'reason_code' => $reason?->value,
            'reason_note' => $reasonNote,
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
            throw new UserFacingException(title: 'Akses ditolak', message: 'Assignment ini bukan milik Anda.', status: 403);
        }

        if ($assignment->status !== InboundAssignment::STATUS_PENDING) {
            throw new UserFacingException(title: 'Status tidak sesuai', message: "Assignment sudah berstatus {$assignment->status}.", status: 409);
        }

        $assignment->update([
            'status' => InboundAssignment::STATUS_IN_PROGRESS,
            'started_at' => now(),
        ]);

        return $assignment->fresh()->load('inbound.items', 'worker:id,name');
    }

    public function lookupByQr(string $code, ?string $inboundId = null): InboundItem
    {
        $code = trim($code);
        $item = null;

        if (Str::isUuid($code)) {
            $item = $this->inboundRepository->findItemByUuid($code);

            if ($item && $inboundId && $item->inbound_id !== $inboundId) {
                $item = null;
            }
        }

        if (! $item) {
            $query = InboundItem::whereHas('variant', fn ($q) => $q->where('sku', $code)->orWhere('barcode', $code));

            if ($inboundId) {

                $query->where('inbound_id', $inboundId);
            } else {

                $query->whereHas('inbound', fn ($q) => $q->whereIn('status', [
                    Inbound::STATUS_DRAFT,
                    Inbound::STATUS_PARTIAL,
                ]))->latest();
            }

            $item = $query->first();
        }

        if (! $item) {
            throw new UserFacingException(title: 'Tidak ditemukan', message: 'QR Code tidak ditemukan.', status: 404);
        }

        return $item->load('inbound.location', 'variant:id,sku,product_id');
    }

    public function lookupCandidatesByQr(string $code): Collection
    {
        $code = trim($code);

        if (Str::isUuid($code)) {
            $direct = $this->inboundRepository->findItemByUuid($code);
            if ($direct) {
                return collect([$direct->load('inbound.location', 'variant:id,sku,product_id')]);
            }
        }

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
        throw new UserFacingException(
            title: 'Gunakan tugas penempatan',
            message: 'Scan penempatan langsung dari inbound sudah dinonaktifkan. Buka tugas PUT agar dokumen, sumber, progres, dan stok diperbarui sekaligus.',
            status: 409,
            errors: ['code' => 'LEGACY_PUTAWAY_DISABLED'],
        );
    }

    private function completeAssignmentIfDone(Inbound $inbound, string $userId): void
    {

        $inbound->loadMissing('items');
        $allPutaway = $inbound->items->isNotEmpty()
            && $inbound->items->every(fn ($i) => $i->isFullyPutaway());

        if (! $allPutaway) {
            return;
        }

        $inbound->assignments()
            ->where('assigned_to', $userId)
            ->where('status', InboundAssignment::STATUS_IN_PROGRESS)
            ->update([
                'status' => InboundAssignment::STATUS_COMPLETED,
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
            throw new UserFacingException(title: 'Aksi tidak dapat diproses', message: 'Tidak ada baris yang dipilih untuk dikoreksi.', status: 422);
        }

        return DB::transaction(function () use ($inboundId, $items, $userId) {
            $inbound = $this->inboundRepository->findByIdForUpdate($inboundId);

            if (! $inbound) {
                throw new UserFacingException(title: 'Tidak ditemukan', message: 'Dokumen Inbound tidak ditemukan.', status: 404);
            }

            if ($inbound->status === Inbound::STATUS_CANCELLED) {
                throw new UserFacingException(title: 'Status tidak sesuai', message: 'Inbound sudah dibatalkan.', status: 409);
            }

            $this->assertWebCanMutate($inbound);

            $defaultBin = $this->binService->getDefaultBin($inbound->location_id);
            if (! $defaultBin) {
                throw new UserFacingException(title: 'Aksi tidak dapat diproses', message: 'Gudang ini belum memiliki Bin Inbound default.', status: 422);
            }

            foreach ($items as $entry) {
                $inboundItemId = $entry['item_id'] ?? null;
                $qty = $entry['qty'] ?? null;

                if (! $inboundItemId) {
                    throw new UserFacingException(title: 'Aksi tidak dapat diproses', message: 'item_id wajib diisi.', status: 422);
                }

                $item = $this->inboundRepository->findItemByUuidForUpdate($inboundItemId);

                if (! $item || $item->inbound_id !== $inbound->id) {
                    throw new UserFacingException(title: 'Tidak ditemukan', message: 'Item inbound tidak ditemukan.', status: 404);
                }

                $qtyRev = $qty ?? (int) $item->received_qty;

                if ($qtyRev <= 0 || $qtyRev > (int) $item->received_qty) {
                    throw new UserFacingException(title: 'Aksi tidak dapat diproses', message: "Qty koreksi tidak valid (maksimal {$item->received_qty}).", status: 422);
                }

                $available = $item->pendingPutawayQty();
                if ($qtyRev > $available) {
                    throw new UserFacingException(title: 'Aksi tidak dapat diproses', message: "Sebagian barang sudah di-putaway atau sedang dalam penempatan aktif; hanya {$available} unit yang masih di bin inbound dan bisa dikoreksi.", status: 422);
                }

                $this->inventoryService->adjustInboundStaging([
                    'item_id' => $item->item_id,
                    'location_id' => $inbound->location_id,
                    'bin_id' => $defaultBin->id,
                    'qty' => -$qtyRev,
                    'transaction_number' => $inbound->transaction_number.'-KOREKSI',
                    'source' => $this->movementSourceFor($inbound),
                    'created_by' => "user:{$userId}",
                ]);

                $this->inboundRepository->updateItemReceivedQty($item->id, -$qtyRev);
            }

            return $this->getById($inboundId);
        });
    }

    public function setDiscrepancyNote(string $inboundId, string $inboundItemId, ?string $note, string $userId): Inbound
    {
        $note = trim((string) $note);

        return DB::transaction(function () use ($inboundId, $inboundItemId, $note) {
            $inbound = $this->inboundRepository->findByIdForUpdate($inboundId);
            if (! $inbound) {
                throw new UserFacingException(title: 'Tidak ditemukan', message: 'Dokumen Inbound tidak ditemukan.', status: 404);
            }
            if ($inbound->status === Inbound::STATUS_CANCELLED) {
                throw new UserFacingException(title: 'Status tidak sesuai', message: 'Inbound sudah dibatalkan.', status: 409);
            }

            $this->assertWebCanMutate($inbound);

            $item = $this->inboundRepository->findItemByUuidForUpdate($inboundItemId);
            if (! $item || $item->inbound_id !== $inbound->id) {
                throw new UserFacingException(title: 'Tidak ditemukan', message: 'Item inbound tidak ditemukan.', status: 404);
            }

            $this->inboundRepository->updateItemDiscrepancyNote($item->id, $note === '' ? null : $note);

            return $this->getById($inboundId);
        });
    }

    private function buildQtyCorrectionNote(Inbound $inbound, InboundItem $item, int $before, int $after, string $userId): string
    {
        $delta = $after - $before;
        $sign = $delta > 0 ? '+' : '';
        $sku = ProductVariant::find($item->item_id)?->sku;
        $actor = User::find($userId)?->name ?? 'sistem';

        return sprintf(
            'Koreksi qty diterima %s%s: %d → %d (%s%d) oleh %s',
            $inbound->transaction_number,
            $sku ? " · {$sku}" : '',
            $before,
            $after,
            $sign,
            $delta,
            $actor,
        );
    }

    public function setReceivedQty(string $inboundId, string $inboundItemId, int $targetQty, string $userId, ?string $expectedUpdatedAt = null, ?string $reasonNote = null): Inbound
    {
        if ($targetQty < 0) {
            throw new UserFacingException(title: 'Aksi tidak dapat diproses', message: 'Jumlah tidak boleh negatif.', status: 422);
        }

        $reasonNote = trim((string) $reasonNote);

        return DB::transaction(function () use ($inboundId, $inboundItemId, $targetQty, $userId, $expectedUpdatedAt, $reasonNote) {
            $inbound = $this->inboundRepository->findByIdForUpdate($inboundId);
            if (! $inbound) {
                throw new UserFacingException(title: 'Tidak ditemukan', message: 'Dokumen Inbound tidak ditemukan.', status: 404);
            }
            if ($inbound->status === Inbound::STATUS_CANCELLED) {
                throw new UserFacingException(title: 'Status tidak sesuai', message: 'Inbound sudah dibatalkan.', status: 409);
            }

            $this->assertVersionMatches($inbound, $expectedUpdatedAt);

            $item = $this->inboundRepository->findItemByUuidForUpdate($inboundItemId);
            if (! $item || $item->inbound_id !== $inbound->id) {
                throw new UserFacingException(title: 'Tidak ditemukan', message: 'Item inbound tidak ditemukan.', status: 404);
            }

            $current = (int) $item->received_qty;
            $delta = $targetQty - $current;
            if ($delta === 0) {
                return $this->getById($inboundId);
            }

            if ($targetQty < (int) $item->putaway_qty) {
                throw new UserFacingException(title: 'Aksi tidak dapat diproses', message: "Jumlah diterima tidak bisa di bawah yang sudah ditempatkan ke rak ({$item->putaway_qty}). Batalkan/kurangi penempatan dulu.", status: 422);
            }

            $defaultBin = $this->binService->getDefaultBin($inbound->location_id);
            if (! $defaultBin) {
                throw new UserFacingException(title: 'Aksi tidak dapat diproses', message: 'Gudang ini belum memiliki Bin Inbound default.', status: 422);
            }

            $note = $reasonNote !== ''
                ? $reasonNote
                : $this->buildQtyCorrectionNote($inbound, $item, $current, $targetQty, $userId);

            $this->inventoryService->adjustInboundStaging([
                'item_id' => $item->item_id,
                'location_id' => $inbound->location_id,
                'bin_id' => $defaultBin->id,
                'qty' => $delta,
                'transaction_number' => $inbound->transaction_number.'-KOREKSI-QTY',
                'source' => 'INBOUND_QTY_CORRECTION',
                'created_by' => ActorName::resolve($userId),
            ]);

            $this->inboundRepository->updateItemReceivedQty($item->id, $delta);

            if ($delta < 0) {
                $this->putawayService->reduceOpenTargetForInboundItem($item->id, -$delta);
            }

            InboundReceipt::create([
                'inbound_item_id' => $item->id,
                'qty' => $delta,
                'bin_id' => $defaultBin->id,
                'condition' => 'ADJUSTMENT',
                'notes' => $note,
                'received_by_user_id' => $userId,
                'received_by' => ActorName::resolve($userId),
                'received_date' => now(),
            ]);

            $inbound->forceFill(['updated_version_at' => now()])->save();

            return $this->getById($inboundId);
        });
    }

    public function cancel(string $inboundId, ?string $userId = null): Inbound
    {
        return DB::transaction(function () use ($inboundId, $userId) {
            $inbound = $this->inboundRepository->findByIdForUpdate($inboundId);

            if (! $inbound) {
                throw new UserFacingException(title: 'Tidak ditemukan', message: 'Dokumen Inbound tidak ditemukan.', status: 404);
            }

            if ($inbound->status === Inbound::STATUS_CANCELLED) {
                throw new UserFacingException(title: 'Status tidak sesuai', message: 'Inbound sudah dibatalkan.', status: 409);
            }

            if ($inbound->status === Inbound::STATUS_DRAFT) {
                throw new UserFacingException(title: 'Status tidak sesuai', message: 'Inbound DRAFT tidak perlu dibatalkan.', status: 409);
            }

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

            $this->assertNoStockShortfall($inbound);

            $hasPutaway = $inbound->items->contains(fn ($it) => (int) $it->putaway_qty > 0);
            if ($hasPutaway) {
                $this->putawayService->reverseAndDeleteForInbound($inbound->id, $userId ?? 'system');

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
            $binId = $p->bin_id ?? null;
            if (! $variantId || ! $binId) {
                continue;
            }
            $key = $variantId.'|'.$binId;
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
                    'bin_id' => $binId,
                    'shortfall' => $need - $current,
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
            .implode("\n", $lines)
            ."\n\nSebagian barang sudah dipicking/dipindah. Batalkan pesanan/transfer terkait dulu.";

        throw new UserFacingException(title: 'Aksi tidak dapat diproses', message: $message, status: 422);
    }

    private function rollbackPurchaseOrderReceipt(Inbound $inbound): void
    {
        $po = PurchaseOrder::with('items')
            ->whereKey($inbound->source_id)
            ->lockForUpdate()
            ->first();

        if ($po) {
            $this->recomputePurchaseOrderReceived($po, $inbound->id);
        }
    }

    public function recomputePurchaseOrderReceived(PurchaseOrder $po, ?string $excludeInboundId = null): void
    {
        $otherActiveInbounds = Inbound::where('source_type', 'purchase_order')
            ->where('source_id', $po->id)
            ->where('status', '!=', Inbound::STATUS_CANCELLED)
            ->when($excludeInboundId, fn ($q) => $q->where('id', '!=', $excludeInboundId))
            ->with('items')
            ->get();

        $po->load('items');

        $totalReceivedByVariant = [];
        foreach ($otherActiveInbounds as $inb) {
            foreach ($inb->items as $item) {
                $vid = $item->item_id;
                $totalReceivedByVariant[$vid] = ($totalReceivedByVariant[$vid] ?? 0) + (int) $item->received_qty;
            }
        }

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

        $newStatus = $po->recomputeStatus();

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
                $this->inventoryService->adjustInboundStaging([
                    'item_id' => $item->item_id,
                    'location_id' => $inbound->location_id,
                    'bin_id' => $defaultBin->id,
                    'qty' => -$reverseQty,
                    'transaction_number' => $inbound->transaction_number.'-CANCEL',
                    'source' => $this->movementSourceFor($inbound),
                    'created_by' => $createdBy,
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
                $this->applyRollbackInventoryDelta([
                    'item_id' => $item->item_id,
                    'location_id' => $inbound->location_id,
                    'bin_id' => $defaultBin->id,
                    'qty' => -$qty,
                    'transaction_number' => $inbound->transaction_number.'-REVERT',
                    'source' => 'TRANSFER_REVERT',
                    'created_by' => $createdBy,
                ]);
            }

            $this->applyRollbackInventoryDelta([
                'item_id' => $item->item_id,
                'location_id' => $transitLocationId,
                'bin_id' => $transitBinId,
                'qty' => $qty,
                'transaction_number' => $inbound->transaction_number.'-REVERT',
                'source' => 'TRANSFER_REVERT',
                'created_by' => $createdBy,
            ]);

            $item->update(['received_qty' => 0]);
        }

        $this->inboundRepository->updateStatus($inbound, Inbound::STATUS_DRAFT);

        $transfer = InventoryTransfer::whereKey($inbound->source_id)
            ->lockForUpdate()
            ->first();

        if ($transfer && $transfer->status === InventoryTransfer::STATUS_RECEIVED) {
            $transfer->update([
                'status' => InventoryTransfer::STATUS_IN_TRANSIT,
                'receive_number' => null,
                'received_by' => null,
                'received_at' => null,
            ]);
        }
    }

    private function applyRollbackInventoryDelta(array $data): void
    {
        $alreadyRecorded = InventoryMovement::query()
            ->where('transaction_number', $data['transaction_number'])
            ->where('item_id', $data['item_id'])
            ->where('location_id', $data['location_id'])
            ->where('bin_id', $data['bin_id'])
            ->where('source', $data['source'])
            ->exists();

        if ($alreadyRecorded) {
            return;
        }

        $inventory = $this->inventoryRepository->findOrCreateForUpdate(
            $data['item_id'],
            $data['location_id'],
            $data['bin_id'],
        );
        $inventory->on_hand += (int) $data['qty'];
        $this->inventoryRepository->updateStock($inventory);

        $movement = InventoryMovement::create([
            'item_id' => $data['item_id'],
            'location_id' => $data['location_id'],
            'bin_id' => $data['bin_id'],
            'transaction_number' => $data['transaction_number'],
            'source' => $data['source'],
            'qty' => $data['qty'],
            'balance' => $inventory->on_hand,
            'transaction_date' => now(),
            'created_by' => $data['created_by'],
        ]);

        $this->movementReversalVisibility->pairReversal($movement);
    }

    public function downloadBarcodes(string $id)
    {
        $inbound = Inbound::with(['items.variant.product'])->findOrFail($id);

        $pages = [];
        foreach ($inbound->items as $item) {
            $qty = $item->expected_qty > 0 ? $item->expected_qty : ($item->received_qty > 0 ? $item->received_qty : 1);

            $name = $item->variant?->product?->name
                ?? $item->variant?->name
                ?? 'Produk tidak dikenal';

            for ($i = 0; $i < $qty; $i++) {
                $pages[] = [
                    'sku' => $item->variant?->sku ?? 'UNKNOWN',
                    'name' => $name,
                ];
            }
        }

        $pdf = Pdf::loadView('inbound::pdf.barcodes', compact('pages'))
            ->setPaper([0, 0, 141.73, 85.04], 'landscape');

        return $pdf->stream("barcodes-inbound-{$inbound->transaction_number}.pdf");
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
                if ($qty <= 0) {
                    continue;
                }
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

        $costService = app(PurchaseCostService::class);
        $purchaseCosts = $costService->averageForItemIds($itemIds->all());
        $map = [];
        foreach ($itemIds as $itemId) {
            $map[$itemId] = $purchaseCosts[$itemId]
                ?? $costService->currentCostForItem((string) $itemId, $inbound->location_id);
        }

        return $map;
    }
}
