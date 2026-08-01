<?php

namespace Modules\Inventory\Services;

use App\Enums\AssignmentActionEnum;
use App\Enums\UnassignReasonEnum;
use App\Models\AssignmentHistory;
use App\Traits\EnforcesAssignmentChannel;
use Illuminate\Database\Eloquent\Model;
use Modules\Inventory\Repositories\PutawayRepository;
use Modules\Inventory\Repositories\InventoryRepository;
use Modules\Inventory\Repositories\InventoryMovementRepository;
use Modules\Inventory\Models\Inventory;
use Modules\Warehouse\Models\LocationBin;
use Modules\Inventory\Models\Putaway;
use Modules\Inventory\Models\PutawayItem;
use Modules\Inventory\Models\PutawayPlacement;
use Modules\Inventory\Models\PutawaySource;
use Modules\Inventory\Models\PutawayItemSource;
use Modules\Inventory\Services\InventoryService;
use Modules\Inventory\Services\StockAdjustmentService;
use Modules\Warehouse\Services\LocationBinService;
use Modules\Inbound\Models\Inbound;
use Modules\Inbound\Models\InboundItem;
use Modules\Inventory\Jobs\ProcessPutawayItemJob;
use Modules\Notification\Events\TaskAssigned;
use Modules\Notification\Services\NotificationDispatcher;
use Illuminate\Support\Facades\DB;

class PutawayService
{
    use EnforcesAssignmentChannel;

    public const NOTIF_PERMISSION = 'view-penempatan';

    protected function unlockedOnceColumn(Model $doc): string
    {
        return 'completed_at';
    }

    public function __construct(
        protected PutawayRepository $putawayRepository,
        protected InventoryRepository $inventoryRepository,
        protected InventoryMovementRepository $movementRepository,
        protected InventoryService $inventoryService,
        protected NotificationDispatcher $notifications,
    ) {}

    private function notifyPutawayCompleted(?Putaway $putaway): void
    {
        if (! $putaway) {
            return;
        }

        $number = $putaway->putaway_number ?? substr((string) $putaway->id, 0, 8);

        $this->notifications->toPermission(self::NOTIF_PERMISSION, [
            'type' => 'putaway_completed',
            'title' => 'Putaway selesai',
            'message' => "Dokumen {$number} selesai di-putaway.",
            'data' => [
                'putaway_id' => $putaway->id,
                'putaway_number' => $putaway->putaway_number ?? null,
                'link' => "/dashboard/barang-masuk/putaway/{$putaway->id}",
            ],
        ], excludeUserIds: array_filter([$putaway->assigned_to ?? null]), locationId: $putaway->location_id);
    }

    public function getAllPaginated(int $limit = 10)
    {
        return $this->putawayRepository->getAllPaginated($limit);
    }

    public function getByStatus(string $status, int $limit = 10)
    {
        return $this->putawayRepository->getByStatus($status, $limit);
    }

    public function getStatusCounts(
        ?string $locationId = null,
        ?string $assignedTo = null,
    ): array {
        $query = Putaway::query();
        if ($locationId !== null && $locationId !== '') {
            \App\Support\WarehouseAccess::assert($locationId);
            $query->where('location_id', $locationId);
        } else {
            \App\Support\WarehouseAccess::apply($query);
        }
        if ($assignedTo !== null && $assignedTo !== '') {
            $query->where('assigned_to', $assignedTo);
        }

        $rows = $query
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->toArray();

        $statuses = [
            Putaway::STATUS_NOT_STARTED,
            Putaway::STATUS_IN_PROGRESS,
            Putaway::STATUS_COMPLETED,
            Putaway::STATUS_CANCELLED,
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

    public function listBins(string $locationId, ?string $search = null): array
    {
        \App\Support\WarehouseAccess::assert($locationId);

        $bins = $this->putawayRepository->getPutawayBins($locationId, $search);

        $binCurrentQty = $this->putawayRepository->currentQtyByBin($locationId, $bins->pluck('id')->all());

        return $bins->map(function ($bin) use ($binCurrentQty) {
            return [
                'id' => $bin->id,
                'bin_final_code' => $bin->bin_final_code,
                'current_qty' => $binCurrentQty[$bin->id] ?? 0,
            ];
        })->all();
    }

    public function lookupBin(string $code, string $locationId): ?\Modules\Warehouse\Models\LocationBin
    {
        \App\Support\WarehouseAccess::assert($locationId);

        $bin = $this->putawayRepository->lookupPutawayBin($locationId, $code);

        if (! $bin) {
            return null;
        }

        $bin->current_qty = $this->putawayRepository->sumOnHandForBin($bin->id, $locationId);

        return $bin;
    }

    public function getById(string $id): ?Putaway
    {
        return $this->putawayRepository->findById($id);
    }

    public function getItems(string $putawayId, int $limit = 10)
    {
        $putaway = $this->putawayRepository->findById($putawayId);

        if (!$putaway) {
            throw new \Exception('Putaway tidak ditemukan.');
        }

        $paginated = $this->putawayRepository->getItemsPaginated($putawayId, $limit);

        $this->attachStrictRecommendedBins($putaway, $paginated->getCollection());
        $this->attachInboundSources($putaway, $paginated->getCollection());
        $this->attachRackAssignment($putaway, $paginated->getCollection());

        return $paginated;
    }

    protected function attachRackAssignment(Putaway $putaway, $items): void
    {
        $location = \Modules\Warehouse\Models\Location::find($putaway->location_id);
        if (! $location || ! $location->enforcesStrictBinSku()) {
            foreach ($items as $item) {
                $item->is_rack_assigned = true;
            }

            return;
        }

        $guard = app(\Modules\Warehouse\Services\SkuHomeBinGuard::class);

        foreach ($items as $item) {
            $item->is_rack_assigned =
                $guard->currentHomeBinId($putaway->location_id, $item->item_id) !== null;
        }
    }

    protected function attachInboundSources(Putaway $putaway, $items): void
    {
        foreach ($items as $item) {
            $item->inbound_sources = [];
        }

        if ($putaway->source_type !== 'INBOUND') {
            return;
        }

        $sources = PutawayItemSource::whereIn('putaway_item_id', collect($items)->pluck('id'))
            ->with(['inboundItem:id,inbound_id,item_id,expected_qty,received_qty,putaway_qty,reserved_qty',
                    'inboundItem.inbound:id,transaction_number,updated_version_at,updated_at'])
            ->get()
            ->groupBy('putaway_item_id');

        foreach ($items as $item) {
            $item->inbound_sources = ($sources->get($item->id) ?? collect())
                ->map(function ($src) {
                    $inboundItem = $src->inboundItem;

                    if (! $inboundItem) {
                        return null;
                    }

                    $inbound = $inboundItem->inbound;

                    return [
                        'inbound_id'         => (string) $inboundItem->inbound_id,
                        'inbound_item_id'    => (string) $inboundItem->id,
                        'transaction_number' => $inbound?->transaction_number,
                        'expected_qty'       => (int) $inboundItem->expected_qty,
                        'received_qty'       => (int) $inboundItem->received_qty,
                        'putaway_qty'        => (int) $inboundItem->putaway_qty,
                        'qty_in_putaway'     => (int) $src->qty,

                        'updated_version_at' => optional($inbound?->updated_version_at ?? $inbound?->updated_at)->toIso8601String(),
                    ];
                })
                ->filter()
                ->values()
                ->all();
        }
    }

    protected function attachStrictRecommendedBins(Putaway $putaway, $items): void
    {
        $location = \Modules\Warehouse\Models\Location::find($putaway->location_id);
        $strict = $location && $location->enforcesStrictBinSku();

        if (!$strict) {
            foreach ($items as $item) {
                $item->recommended_bin = null;
                $item->recommended_bin_locked = false;
            }

            return;
        }

        $guard = app(\Modules\Warehouse\Services\SkuHomeBinGuard::class);

        $homeByItem = [];
        foreach ($items as $item) {
            $homeByItem[$item->id] = $guard->currentHomeBinId($putaway->location_id, $item->item_id);
        }

        $binIds = collect($homeByItem)->filter()->unique()->values()->all();
        $bins = \Modules\Warehouse\Models\LocationBin::whereIn('id', $binIds)
            ->get(['id', 'bin_final_code'])
            ->keyBy('id');

        foreach ($items as $item) {
            $homeBinId = $homeByItem[$item->id] ?? null;
            $bin = $homeBinId ? $bins->get($homeBinId) : null;
            $item->recommended_bin = $bin
                ? ['id' => $bin->id, 'bin_final_code' => $bin->bin_final_code]
                : null;
            $item->recommended_bin_locked = $bin !== null;
        }
    }

    public function createFromInbounds(array $inboundIds, ?string $assignedTo, string $userId): Putaway
    {
        $inbounds = Inbound::with(['items'])
            ->whereIn('id', $inboundIds)
            ->get();

        if ($inbounds->pluck('location_id')->unique()->count() > 1) {
            throw new \InvalidArgumentException('Penerimaan harus dari lokasi/gudang yang sama untuk digabung.');
        }

        foreach ($inbounds as $inbound) {
            if ($inbound->status === Inbound::STATUS_CANCELLED) {
                throw new \InvalidArgumentException("Penerimaan {$inbound->transaction_number} sudah {$inbound->status}, tidak bisa dibuat penempatan.");
            }
        }

        $locationId = $inbounds->first()->location_id;
        $defaultBin = app(LocationBinService::class)->getDefaultBin($locationId);

        return DB::transaction(function () use ($inbounds, $defaultBin, $locationId, $userId, $assignedTo) {
            $lockedItems = InboundItem::whereIn('inbound_id', $inbounds->pluck('id'))
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $merged = [];
            $reservationDeltas = [];
            foreach ($inbounds as $inbound) {
                foreach ($inbound->items as $item) {
                    $locked = $lockedItems->get($item->id);
                    if (! $locked) {
                        continue;
                    }
                    $pending = max(0, (int) $locked->received_qty - (int) $locked->putaway_qty - (int) ($locked->reserved_qty ?? 0));
                    if ($pending <= 0) {
                        continue;
                    }

                    if (! isset($merged[$locked->item_id])) {
                        $merged[$locked->item_id] = [
                            'item_id'            => $locked->item_id,
                            'source_bin_id'      => $defaultBin ? $defaultBin->id : null,
                            'destination_bin_id' => null,
                            'qty'                => 0,
                            'batch_no'           => null,
                            'serial_no'          => null,
                            'sources'            => [],
                        ];
                    }

                    $merged[$locked->item_id]['qty'] += $pending;
                    $merged[$locked->item_id]['sources'][] = [
                        'inbound_item_id' => $locked->id,
                        'qty'             => $pending,
                    ];
                    $reservationDeltas[$locked->id] = ($reservationDeltas[$locked->id] ?? 0) + $pending;
                }
            }

            $items = array_values($merged);

            if (empty($items)) {
                throw new \RuntimeException('Tidak ada item pending untuk di-putaway (semua qty sudah masuk penempatan aktif atau sudah selesai).');
            }

            $notes = $inbounds->count() === 1
                ? "Manual Putaway from Inbound {$inbounds->first()->transaction_number}"
                : 'Manual Putaway gabungan dari ' . $inbounds->count() . ' penerimaan: ' . $inbounds->pluck('transaction_number')->implode(', ');

            $putaway = $this->create([
                'location_id' => $locationId,
                'source_type' => 'INBOUND',
                'source_id'   => $inbounds->count() === 1 ? $inbounds->first()->id : null,
                'sources'     => $inbounds->pluck('id')->all(),
                'notes'       => $notes,
                'created_by'  => $userId,
                'items'       => $items,
            ]);

            foreach ($reservationDeltas as $inboundItemId => $delta) {
                InboundItem::where('id', $inboundItemId)->increment('reserved_qty', $delta);
            }

            if ($assignedTo) {
                $this->assignStaff([
                    'performed_by' => $userId,
                    'data' => [
                        [
                            'putaway_id'  => $putaway->id,
                            'assigned_to' => $assignedTo,
                        ],
                    ],
                ]);
            }

            return $putaway;
        });
    }

    public function attachRecommendedBins(Putaway $putaway): void
    {
        $items = $putaway->items ?? collect();
        if ($items->isEmpty()) {
            return;
        }

        $locationId = $putaway->location_id;
        $itemIds = $items->pluck('item_id')->filter()->unique()->values()->all();

        $stocks = Inventory::query()
            ->whereIn('item_id', $itemIds)
            ->where('location_id', $locationId)
            ->where('on_hand', '>', 0)
            ->whereNotNull('bin_id')
            ->whereHas('bin', fn ($q) => $q->where('is_inbound', false))
            ->with('bin:id,bin_final_code')
            ->orderByDesc('on_hand')
            ->get(['id', 'item_id', 'bin_id', 'on_hand']);

        $byItem = $stocks->groupBy('item_id');

        $allBins = LocationBin::where('location_id', $locationId)
            ->where('is_inbound', false)
            ->orderBy('bin_final_code')
            ->get(['id', 'bin_final_code']);

        $binItemIds = Inventory::where('location_id', $locationId)
            ->where('on_hand', '>', 0)
            ->whereNotNull('bin_id')
            ->select('bin_id', 'item_id')
            ->distinct()
            ->get()
            ->groupBy('bin_id')
            ->map(fn ($rows) => $rows->pluck('item_id')->unique()->values()->all());

        $usedBinItems = [];

        foreach ($items as $item) {
            $remaining = (int) $item->qty;
            $plan = [];
            $usedBinIds = [];

            $itemStocks = $byItem->get($item->item_id, collect());
            foreach ($itemStocks as $stock) {
                if ($remaining <= 0) break;
                $bin = $stock->bin;
                if (!$bin) continue;

                $existingRecommended = $usedBinItems[$bin->id] ?? [];
                if (!empty($existingRecommended) && !in_array($item->item_id, $existingRecommended)) {
                    continue;
                }

                $allocate = $remaining;
                $plan[] = ['code' => $bin->bin_final_code, 'qty' => $allocate];
                $usedBinItems[$bin->id][] = $item->item_id;
                $usedBinIds[] = $bin->id;
                $remaining -= $allocate;
            }

            if ($remaining > 0) {
                foreach ($allBins as $bin) {
                    if ($remaining <= 0) break;
                    if (in_array($bin->id, $usedBinIds)) continue;

                    $existingItemIds = array_unique(array_merge(
                        $binItemIds[$bin->id] ?? [],
                        $usedBinItems[$bin->id] ?? []
                    ));
                    if (!empty($existingItemIds) && !in_array($item->item_id, $existingItemIds)) {
                        continue;
                    }

                    $allocate = $remaining;
                    $plan[] = ['code' => $bin->bin_final_code, 'qty' => $allocate];
                    $usedBinItems[$bin->id][] = $item->item_id;
                    $usedBinIds[] = $bin->id;
                    $remaining -= $allocate;
                }
            }

            $item->recommended_bins = $plan;
        }
    }

    public function create(array $data): Putaway
    {
        return DB::transaction(function () use ($data) {

            $inboundItemIds = collect($data['items'] ?? [])
                ->flatMap(fn ($item) => collect($item['sources'] ?? [])->pluck('inbound_item_id'))
                ->filter()
                ->unique()
                ->values()
                ->all();

            if (! empty($inboundItemIds)) {
                $locked = InboundItem::whereIn('id', $inboundItemIds)
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                $requestedPerItem = collect($data['items'] ?? [])
                    ->flatMap(fn ($item) => $item['sources'] ?? [])
                    ->groupBy('inbound_item_id')
                    ->map(fn ($rows) => collect($rows)->sum('qty'));

                foreach ($requestedPerItem as $inboundItemId => $requested) {
                    $item = $locked->get($inboundItemId);
                    if (! $item) {
                        throw new \Exception("Item inbound {$inboundItemId} tidak ditemukan.");
                    }
                    $pending = (int) $item->received_qty - (int) $item->putaway_qty;
                    if ((int) $requested > $pending) {
                        throw new \Exception(
                            "Qty penempatan ({$requested}) melebihi sisa yang bisa diputaway ({$pending}) untuk item {$item->item_id}. "
                            . "Kemungkinan sudah ada putaway lain untuk item ini — refresh halaman."
                        );
                    }
                }
            }

            $putawayNo = $this->putawayRepository->generatePutawayNo();

            $putaway = $this->putawayRepository->create([
                'putaway_no' => $putawayNo,
                'location_id' => $data['location_id'],
                'source_type' => $data['source_type'] ?? 'MANUAL',
                'source_id' => $data['source_id'] ?? null,
                'status' => Putaway::STATUS_NOT_STARTED,
                'notes' => $data['notes'] ?? null,
                'created_by' => $data['created_by'],
            ]);

            foreach ($data['items'] as $itemData) {
                $putawayItem = $this->putawayRepository->createItem([
                    'putaway_id' => $putaway->id,
                    'item_id' => $itemData['item_id'],
                    'source_bin_id' => $itemData['source_bin_id'],
                    'destination_bin_id' => $itemData['destination_bin_id'] ?? null,
                    'qty' => $itemData['qty'],
                    'putaway_qty' => 0,
                    'batch_no' => $itemData['batch_no'] ?? null,
                    'serial_no' => $itemData['serial_no'] ?? null,
                ]);

                foreach ($itemData['sources'] ?? [] as $src) {
                    PutawayItemSource::create([
                        'putaway_item_id' => $putawayItem->id,
                        'inbound_item_id' => $src['inbound_item_id'],
                        'qty' => $src['qty'],
                        'putaway_qty' => 0,
                    ]);
                }
            }

            foreach (array_unique($data['sources'] ?? []) as $inboundId) {
                PutawaySource::create([
                    'putaway_id' => $putaway->id,
                    'inbound_id' => $inboundId,
                ]);
            }

            return $this->putawayRepository->findById($putaway->id);
        });
    }

    public function unassign(
        string $putawayId,
        string $actorId,
        UnassignReasonEnum $reason,
        ?string $reasonNote = null,
        ?string $newAssigneeId = null,
    ): Putaway {
        return DB::transaction(function () use ($putawayId, $actorId, $reason, $reasonNote, $newAssigneeId) {
            $putaway = Putaway::lockForUpdate()->findOrFail($putawayId);
            $previousAssignee = $putaway->assigned_to;
            $isSelf = $previousAssignee !== null && (string) $previousAssignee === $actorId;
            $action = $isSelf ? AssignmentActionEnum::SELF_UNASSIGN : AssignmentActionEnum::UNASSIGN;

            $putaway->forceFill([
                'assigned_to' => $newAssigneeId,
                'assigned_by' => $newAssigneeId ? $actorId : null,
                'assigned_at' => $newAssigneeId ? now() : null,
                'updated_version_at' => now(),
            ])->save();

            AssignmentHistory::create([
                'subject_type' => Putaway::class,
                'subject_id'   => $putaway->id,
                'from_user_id' => $previousAssignee,
                'to_user_id'   => $newAssigneeId,
                'actor_id'     => $actorId,
                'action'       => $action->value,
                'channel'      => $this->currentChannel()->value,
                'reason_code'  => $reason->value,
                'reason_note'  => $reasonNote,
            ]);

            return $putaway->fresh();
        });
    }

    public function resetAssignmentDestructive(
        string $putawayId,
        string $actorId,
        string $reasonNote,
        ?string $newAssigneeId = null,
    ): Putaway {
        return DB::transaction(function () use ($putawayId, $actorId, $reasonNote, $newAssigneeId) {
            $putaway = Putaway::lockForUpdate()->findOrFail($putawayId);
            $previousAssignee = $putaway->assigned_to;

            $this->resetAllPlacements($putawayId, $actorId);

            $putaway->refresh()->forceFill([
                'assigned_to' => $newAssigneeId,
                'assigned_by' => $newAssigneeId ? $actorId : null,
                'assigned_at' => $newAssigneeId ? now() : null,
                'status'      => Putaway::STATUS_NOT_STARTED,
                'started_at'  => null,
                'completed_at' => null,
                'updated_version_at' => now(),
            ])->save();

            AssignmentHistory::create([
                'subject_type' => Putaway::class,
                'subject_id'   => $putaway->id,
                'from_user_id' => $previousAssignee,
                'to_user_id'   => $newAssigneeId,
                'actor_id'     => $actorId,
                'action'       => AssignmentActionEnum::FORCE_RESET->value,
                'channel'      => $this->currentChannel()->value,
                'reason_code'  => UnassignReasonEnum::FORCE_RESET->value,
                'reason_note'  => $reasonNote,
            ]);

            return $putaway->fresh();
        });
    }

    public function assignStaff(array $data): array
    {
        $results = [];

        foreach ($data['data'] as $assignment) {
            $putaway = $this->putawayRepository->findByIdForUpdate($assignment['putaway_id']);

            if (!$putaway) {
                throw new \Exception("Putaway {$assignment['putaway_id']} tidak ditemukan.");
            }

            if ($putaway->status === Putaway::STATUS_COMPLETED || $putaway->status === Putaway::STATUS_CANCELLED) {
                throw new \Exception("Putaway {$putaway->putaway_no} sudah {$putaway->status}, tidak bisa di-assign.");
            }

            $previousAssignee = $putaway->assigned_to;
            $action = $previousAssignee === null
                ? AssignmentActionEnum::ASSIGN
                : AssignmentActionEnum::REASSIGN;

            $putaway->update([
                'assigned_to' => $assignment['assigned_to'],
                'assigned_by' => $data['performed_by'],
                'assigned_at' => now(),
                'updated_version_at' => now(),
            ]);

            AssignmentHistory::create([
                'subject_type' => Putaway::class,
                'subject_id'   => $putaway->id,
                'from_user_id' => $previousAssignee,
                'to_user_id'   => $assignment['assigned_to'],
                'actor_id'     => $data['performed_by'],
                'action'       => $action->value,
                'channel'      => $this->currentChannel()->value,
            ]);

            $putaway = $this->putawayRepository->findById($assignment['putaway_id']);

            TaskAssigned::dispatch(
                $assignment['assigned_to'],
                'putaway',
                $putaway->putaway_no,
                $data['performed_by'],
                ['putaway_id' => $assignment['putaway_id']],
            );

            $results[] = $putaway;
        }

        return $results;
    }

    public function start(string $id): Putaway
    {
        return DB::transaction(function () use ($id) {
            $putaway = $this->putawayRepository->findByIdForUpdate($id);

            if (!$putaway) {
                throw new \Exception('Putaway tidak ditemukan.');
            }

            if ($putaway->status !== Putaway::STATUS_NOT_STARTED) {
                throw new \Exception("Hanya putaway NOT_STARTED yang bisa di-start (status saat ini: {$putaway->status}).");
            }

            $this->putawayRepository->updateStatus($id, Putaway::STATUS_IN_PROGRESS, [
                'started_at' => now(),
            ]);

            return $this->putawayRepository->findById($id);
        });
    }

    public function processItem(string $putawayId, string $itemId, array $data): void
    {
        $putaway = $this->putawayRepository->findById($putawayId);

        if (!$putaway) {
            throw new \Exception('Putaway tidak ditemukan.');
        }

        $actorId = (string) ($data['actor_id'] ?? request()?->user()?->id ?? '');
        $channel = $this->currentChannel();
        if ($channel === \App\Enums\ClientChannelEnum::MOBILE) {
            $this->assertMobileCanMutate($putaway, $actorId);
        } else {

            $this->assertVersionMatches($putaway, $data['_expected_updated_at'] ?? null);
        }

        $item = PutawayItem::where('putaway_id', $putawayId)
            ->where('id', $itemId)
            ->first();
        if (! $item) {
            throw new \Exception('Item putaway tidak ditemukan.');
        }

        $location = \Modules\Warehouse\Models\Location::find($putaway->location_id);
        if ($location && $location->enforcesStrictBinSku()) {
            $guard = app(\Modules\Warehouse\Services\SkuHomeBinGuard::class);
            if ($guard->currentHomeBinId($putaway->location_id, $item->item_id) === null) {
                throw new \DomainException('Rak belum diassign, silahkan hubungi admin.');
            }
        }

        if ($putaway->status === Putaway::STATUS_NOT_STARTED) {
            $this->putawayRepository->updateStatus($putawayId, Putaway::STATUS_IN_PROGRESS, [
                'started_at' => now(),
            ]);
        } elseif ($putaway->status !== Putaway::STATUS_IN_PROGRESS) {
            throw new \Exception("Putaway harus IN_PROGRESS untuk memproses item (status saat ini: {$putaway->status}).");
        }

        ProcessPutawayItemJob::dispatchSync($putawayId, $itemId, $data);
    }

    public function complete(string $id): Putaway
    {
        $putaway = DB::transaction(function () use ($id) {
            $putaway = $this->putawayRepository->findByIdForUpdate($id);

            if (!$putaway) {
                throw new \Exception('Putaway tidak ditemukan.');
            }

            if ($putaway->status !== Putaway::STATUS_IN_PROGRESS) {
                throw new \Exception("Hanya putaway IN_PROGRESS yang bisa di-complete (status saat ini: {$putaway->status}).");
            }

            $putaway->load('items');

            $anyProcessed = $putaway->items->contains(fn ($item) => (int) $item->putaway_qty > 0);
            if (! $anyProcessed) {
                throw new \Exception('Minimal 1 item harus sudah ditempatkan sebelum menyelesaikan.');
            }

            foreach ($putaway->items as $item) {
                $unplaced = (int) $item->qty - (int) $item->putaway_qty;
                if ($unplaced > 0 && $putaway->source_type === 'INBOUND') {
                    $this->releasePartialReservation($putaway, $item, $unplaced);
                }

            }

            $this->putawayRepository->updateStatus($id, Putaway::STATUS_COMPLETED, [
                'completed_at' => now(),
            ]);

            if ($putaway->source_type === 'INBOUND') {
                foreach ($this->sourceInbounds($putaway) as $inbound) {
                    $this->recomputeInboundStatus($inbound);
                }
            }

            return $this->putawayRepository->findById($id);
        });

        $this->notifyPutawayCompleted($putaway);

        return $putaway;
    }

    public function deletePlacement(string $putawayId, string $itemId, string $placementId, ?int $qty, string $userId): Putaway
    {
        return $this->deletePlacements($putawayId, [
            ['item_id' => $itemId, 'placement_id' => $placementId, 'qty' => $qty],
        ], $userId);
    }

    public function deletePlacements(string $putawayId, array $items, string $userId): Putaway
    {
        if (empty($items)) {
            throw new \Exception('Tidak ada penempatan yang dipilih untuk dikoreksi.');
        }

        return DB::transaction(function () use ($putawayId, $items, $userId) {
            $putaway = $this->putawayRepository->findByIdForUpdate($putawayId);

            if (! $putaway) {
                throw new \Exception('Putaway tidak ditemukan.');
            }

            if (! in_array($putaway->status, [Putaway::STATUS_IN_PROGRESS, Putaway::STATUS_COMPLETED], true)) {
                throw new \Exception("Hanya putaway IN_PROGRESS atau COMPLETED yang bisa dikoreksi (status saat ini: {$putaway->status}).");
            }

            $wasCompleted = $putaway->status === Putaway::STATUS_COMPLETED;

            foreach ($items as $entry) {
                $this->reverseOnePlacement($putaway, $entry, $userId, $wasCompleted);
            }

            if ($wasCompleted) {
                $this->createCorrectionAdjustment($putaway, $items, $userId);

                foreach ($items as $entry) {
                    $item = PutawayItem::find($entry['item_id']);
                    if ($item) {
                        $item->update(['qty' => $item->putaway_qty]);
                    }
                }
            } else {
                if ($putaway->source_type === 'INBOUND') {
                    foreach ($this->sourceInbounds($putaway) as $inbound) {
                        $this->recomputeInboundStatus($inbound);
                    }
                }
            }

            Putaway::where('id', $putawayId)->update(['updated_version_at' => now()]);

            return $this->putawayRepository->findById($putawayId);
        });
    }

    public function deletePutaway(string $id, string $userId): array
    {
        return DB::transaction(function () use ($id, $userId) {
            $putaway = $this->putawayRepository->findByIdForUpdate($id);

            if (! $putaway) {
                throw new \Exception('Putaway tidak ditemukan.');
            }

            $status = $putaway->status;

            $hasPlacements = PutawayPlacement::whereIn(
                'putaway_item_id',
                PutawayItem::where('putaway_id', $id)->pluck('id')
            )->exists();

            if ($status === Putaway::STATUS_CANCELLED) {
                throw new \Exception('Putaway yang sudah dibatalkan tidak bisa dihapus.');
            }

            if ($status === Putaway::STATUS_NOT_STARTED) {
                if ($hasPlacements) {
                    $this->resetAllPlacements($putaway, $userId);
                }

                $inbounds = $putaway->source_type === 'INBOUND'
                    ? $this->sourceInbounds($putaway)
                    : collect();

                $this->hardDeletePutaway($putaway);

                foreach ($inbounds as $inbound) {
                    $this->recomputeInboundStatus($inbound);
                }

                return ['id' => $id, 'action' => 'unassigned'];
            }

            $this->resetAllPlacements($putaway, $userId);

            $target = $status === Putaway::STATUS_COMPLETED
                ? Putaway::STATUS_IN_PROGRESS
                : Putaway::STATUS_NOT_STARTED;

            $extra = $status === Putaway::STATUS_COMPLETED
                ? ['completed_at' => null]
                : ['started_at' => null, 'completed_at' => null];

            $this->putawayRepository->updateStatus($id, $target, $extra);

            if ($putaway->source_type === 'INBOUND') {
                foreach ($this->sourceInbounds($putaway) as $inbound) {
                    $this->recomputeInboundStatus($inbound);
                }
            }

            return [
                'id' => $id,
                'action' => $status === Putaway::STATUS_COMPLETED ? 'reset_in_progress' : 'reset_not_started',
            ];
        });
    }

    public function bulkDeletePutaway(array $ids, string $userId): array
    {
        $ids = array_values(array_unique($ids));

        if (empty($ids)) {
            throw new \Exception('Tidak ada penempatan yang dipilih untuk dihapus.');
        }

        return DB::transaction(function () use ($ids, $userId) {
            $results = [];
            foreach ($ids as $id) {
                $results[] = $this->deletePutaway($id, $userId);
            }
            return $results;
        });
    }

    public function reverseAndDeleteForInbound(string $inboundId, string $userId): void
    {
        $putawayIds = PutawaySource::where('inbound_id', $inboundId)
            ->pluck('putaway_id')
            ->unique()
            ->all();

        if (empty($putawayIds)) {
            return;
        }

        foreach ($putawayIds as $putawayId) {
            $putaway = $this->putawayRepository->findByIdForUpdate($putawayId);
            if (! $putaway) {
                continue;
            }

            $this->resetAllPlacements($putaway, $userId);
            $this->hardDeletePutaway($putaway);
        }
    }

    private function resetAllPlacements(Putaway $putaway, string $userId): void
    {
        $putaway->load(['items.placements']);

        foreach ($putaway->items as $item) {
            foreach ($item->placements as $placement) {
                $this->reverseOnePlacement($putaway, [
                    'item_id'      => $item->id,
                    'placement_id' => $placement->id,
                    'qty'          => null,
                ], $userId);
            }
        }
    }

    private function hardDeletePutaway(Putaway $putaway): void
    {
        $itemIds = PutawayItem::where('putaway_id', $putaway->id)->pluck('id');

        if ($putaway->source_type === 'INBOUND') {
            $this->releaseReservationsForItems($putaway, $itemIds);
        }

        PutawayItemSource::whereIn('putaway_item_id', $itemIds)->delete();
        PutawayPlacement::whereIn('putaway_item_id', $itemIds)->delete();
        PutawayItem::where('putaway_id', $putaway->id)->delete();
        PutawaySource::where('putaway_id', $putaway->id)->delete();

        $putaway->delete();
    }

    private function releaseReservationsForItems(Putaway $putaway, $itemIds): void
    {
        $pivotSources = PutawayItemSource::whereIn('putaway_item_id', $itemIds)
            ->get(['inbound_item_id', 'qty', 'putaway_qty']);

        if ($pivotSources->isNotEmpty()) {
            foreach ($pivotSources as $src) {
                $release = max(0, (int) $src->qty - (int) $src->putaway_qty);
                if ($release <= 0) {
                    continue;
                }
                InboundItem::where('id', $src->inbound_item_id)
                    ->update(['reserved_qty' => DB::raw('GREATEST(reserved_qty - ' . (int) $release . ', 0)')]);
            }
            return;
        }

        if ($putaway->source_id) {
            $legacyItems = PutawayItem::whereIn('id', $itemIds)->get(['item_id', 'qty', 'putaway_qty']);
            foreach ($legacyItems as $pi) {
                $release = max(0, (int) $pi->qty - (int) $pi->putaway_qty);
                if ($release <= 0) {
                    continue;
                }
                InboundItem::where('inbound_id', $putaway->source_id)
                    ->where('item_id', $pi->item_id)
                    ->update(['reserved_qty' => DB::raw('GREATEST(reserved_qty - ' . (int) $release . ', 0)')]);
            }
        }
    }

    public function getManyForPdf(array $ids)
    {
        return $this->putawayRepository->getManyWithDetails($ids);
    }

    public function getManyForPdfOrFail(array $ids)
    {
        $putaways = $this->getManyForPdf($ids);

        if ($putaways->count() !== count($ids)) {
            $missing = array_values(array_diff($ids, $putaways->pluck('id')->all()));

            throw new \App\Exceptions\UserFacingException(
                'Data tidak ditemukan',
                'Sebagian penempatan tidak ditemukan: ' . implode(', ', $missing),
                404,
            );
        }

        return $putaways;
    }

    public function messageForDeleteAction(?string $action): string
    {
        return match ($action) {
            'unassigned' => 'Penempatan dihapus, penerimaan dikembalikan.',
            'reset_not_started' => 'Penempatan direset ke Belum Mulai.',
            'reset_in_progress' => 'Penempatan dikembalikan ke Sedang Diproses.',
            default => 'Penempatan diperbarui.',
        };
    }

    public function resolvePdfSourceLabel(Putaway $putaway): string
    {
        if ($putaway->source_type === 'INBOUND') {
            if ($putaway->inbound) {
                return $putaway->inbound->reference_number
                    ?? $putaway->inbound->transaction_number
                    ?? '-';
            }

            $sources = $putaway->relationLoaded('sources') ? $putaway->sources : collect();
            if ($sources->isNotEmpty()) {
                return $sources
                    ->map(fn ($i) => $i->reference_number ?? $i->transaction_number)
                    ->filter()
                    ->implode(', ') ?: '-';
            }
        }

        return '-';
    }

    public function loadForPdf(Putaway $putaway): Putaway
    {
        $putaway->load(['inbound', 'sources:id,reference_number,transaction_number', 'location']);
        $this->attachRecommendedBins($putaway);

        return $putaway;
    }

    private function reverseOnePlacement(Putaway $putaway, array $entry, string $userId, bool $stayCompleted = false): void
    {
        $itemId = $entry['item_id'] ?? null;
        $placementId = $entry['placement_id'] ?? null;
        $qty = $entry['qty'] ?? null;

        if (! $itemId || ! $placementId) {
            throw new \Exception('item_id dan placement_id wajib diisi.');
        }

        $item = $this->putawayRepository->findItemForUpdate($putaway->id, $itemId);

        if (! $item) {
            throw new \Exception('Item putaway tidak ditemukan.');
        }

        if (empty($item->source_bin_id)) {
            throw new \Exception('Item putaway tidak punya rak asal, tidak bisa dikoreksi.');
        }

        $placement = PutawayPlacement::where('id', $placementId)
            ->where('putaway_item_id', $item->id)
            ->lockForUpdate()
            ->first();

        if (! $placement) {
            throw new \Exception('Penempatan tidak ditemukan.');
        }

        $qtyRev = $qty ?? (int) $placement->qty;

        if ($qtyRev <= 0 || $qtyRev > (int) $placement->qty) {
            throw new \Exception("Qty koreksi tidak valid (maksimal {$placement->qty}).");
        }

        $this->inventoryService->reverseBinMove([
            'item_id'            => $item->item_id,
            'location_id'        => $putaway->location_id,
            'from_bin_id'        => $placement->bin_id,
            'to_bin_id'          => $item->source_bin_id,
            'qty'                => $qtyRev,
            'batch_no'           => $item->batch_no ?? '',
            'serial_no'          => $item->serial_no ?? '',
            'source'             => 'PUTAWAY_REVERSAL',
            'transaction_number' => $putaway->putaway_no . '-KOREKSI',
            'created_by'         => "user:{$userId}",
        ]);

        if ($qtyRev >= (int) $placement->qty) {
            $placement->delete();
        } else {
            $placement->decrement('qty', $qtyRev);
        }

        $newPutawayQty = max(0, (int) $item->putaway_qty - $qtyRev);
        $item->putaway_qty = $newPutawayQty;
        if ($newPutawayQty === 0) {
            $item->destination_bin_id = null;
        }
        $item->save();

        if ($putaway->source_type === 'INBOUND') {
            $sources = PutawayItemSource::query()
                ->where('putaway_item_sources.putaway_item_id', $item->id)
                ->join('inbound_items', 'inbound_items.id', '=', 'putaway_item_sources.inbound_item_id')
                ->join('inbounds', 'inbounds.id', '=', 'inbound_items.inbound_id')
                ->orderByDesc('inbounds.created_at') 
                ->lockForUpdate()
                ->select('putaway_item_sources.*')
                ->get();

            if ($sources->isNotEmpty()) {
                $remaining = $qtyRev;
                foreach ($sources as $src) {
                    if ($remaining <= 0) {
                        break;
                    }
                    $take = min((int) $src->putaway_qty, $remaining);
                    if ($take <= 0) {
                        continue;
                    }
                    $src->decrement('putaway_qty', $take);

                    $inboundUpdate = [
                        'putaway_qty' => DB::raw('GREATEST(putaway_qty - ' . (int) $take . ', 0)'),
                    ];
                    if (! $stayCompleted) {
                        $inboundUpdate['reserved_qty'] = DB::raw('reserved_qty + ' . (int) $take);
                    }
                    InboundItem::where('id', $src->inbound_item_id)->update($inboundUpdate);
                    $remaining -= $take;
                }
            } elseif ($putaway->source_id) {

                $inboundItem = InboundItem::where('inbound_id', $putaway->source_id)
                    ->where('item_id', $item->item_id)
                    ->first();

                if ($inboundItem) {
                    $take = min($qtyRev, (int) $inboundItem->putaway_qty);
                    $inboundUpdate = [
                        'putaway_qty' => DB::raw('GREATEST(putaway_qty - ' . (int) $take . ', 0)'),
                    ];
                    if (! $stayCompleted) {
                        $inboundUpdate['reserved_qty'] = DB::raw('reserved_qty + ' . (int) $take);
                    }
                    InboundItem::where('id', $inboundItem->id)->update($inboundUpdate);
                }
            }
        }
    }

    private function sourceInbounds(Putaway $putaway)
    {
        $ids = $putaway->sourceRows()->pluck('inbound_id');

        if ($ids->isEmpty() && $putaway->source_id) {
            $ids = collect([$putaway->source_id]);
        }

        if ($ids->isEmpty()) {
            return collect();
        }

        return Inbound::with('items')->whereIn('id', $ids->all())->get();
    }

    private function recomputeInboundStatus(Inbound $inbound): void
    {

    }

    private function releasePartialReservation(Putaway $putaway, PutawayItem $item, int $unplaced): void
    {
        $sources = PutawayItemSource::query()
            ->where('putaway_item_sources.putaway_item_id', $item->id)
            ->join('inbound_items', 'inbound_items.id', '=', 'putaway_item_sources.inbound_item_id')
            ->join('inbounds', 'inbounds.id', '=', 'inbound_items.inbound_id')
            ->orderByDesc('inbounds.created_at')
            ->lockForUpdate()
            ->select('putaway_item_sources.*')
            ->get();

        if ($sources->isNotEmpty()) {
            $remaining = $unplaced;
            foreach ($sources as $src) {
                if ($remaining <= 0) {
                    break;
                }
                $srcUnplaced = (int) $src->qty - (int) $src->putaway_qty;
                if ($srcUnplaced <= 0) {
                    continue;
                }
                $take = min($srcUnplaced, $remaining);
                $src->decrement('qty', $take);
                InboundItem::where('id', $src->inbound_item_id)
                    ->update(['reserved_qty' => DB::raw('GREATEST(reserved_qty - ' . (int) $take . ', 0)')]);
                $remaining -= $take;
            }
        } elseif ($putaway->source_id) {
            InboundItem::where('inbound_id', $putaway->source_id)
                ->where('item_id', $item->item_id)
                ->update(['reserved_qty' => DB::raw('GREATEST(reserved_qty - ' . (int) $unplaced . ', 0)')]);
        }
    }

    private function createCorrectionAdjustment(Putaway $putaway, array $entries, string $userId): void
    {
        $putaway->load('items');
        $adjItems = [];

        foreach ($entries as $entry) {
            $putawayItem = $putaway->items->firstWhere('id', $entry['item_id']);
            if (! $putawayItem) {
                continue;
            }

            $placement = isset($entry['placement_id'])
                ? PutawayPlacement::find($entry['placement_id'])
                : null;

            $sourceBinId = $putawayItem->source_bin_id;
            if (! $sourceBinId) {
                continue;
            }

            $qtyReversed = $entry['qty']
                ?? ($placement ? (int) $placement->qty : 0);
            if ($qtyReversed <= 0) {
                continue;
            }

            $onHand = (int) ($this->inventoryRepository->findExact(
                $putawayItem->item_id,
                $putaway->location_id,
                $sourceBinId,
            )?->on_hand ?? 0);

            $adjItems[] = [
                'item_id'    => $putawayItem->item_id,
                'bin_id'     => $sourceBinId,
                'actual_qty' => $onHand - $qtyReversed,
                'notes'      => "Koreksi penempatan {$putaway->putaway_no}: qty -{$qtyReversed}",
            ];
        }

        if (empty($adjItems)) {
            return;
        }

        $user = \App\Models\User::find($userId);
        $userName = $user?->name ?? $userId;

        app(StockAdjustmentService::class)->create([
            'transaction_date' => now()->toDateString(),
            'location_id'      => $putaway->location_id,
            'created_by'       => $userName,
            'notes'            => "Koreksi penempatan {$putaway->putaway_no} oleh {$userName}",
            'items'            => $adjItems,
        ]);
    }

    public function reduceOpenTargetForInboundItem(string $inboundItemId, int $amount): int
    {
        if ($amount <= 0) {
            return 0;
        }

        $sources = PutawayItemSource::where('inbound_item_id', $inboundItemId)
            ->whereHas('putawayItem.putaway', function ($q) {
                $q->whereNotIn('status', [Putaway::STATUS_COMPLETED, Putaway::STATUS_CANCELLED]);
            })
            ->with('putawayItem.putaway')
            ->lockForUpdate()
            ->get();

        $remaining = $amount;

        foreach ($sources as $src) {
            if ($remaining <= 0) {
                break;
            }

            $srcUnplaced = (int) $src->qty - (int) $src->putaway_qty;
            if ($srcUnplaced <= 0) {
                continue;
            }

            $take = min($srcUnplaced, $remaining);

            $src->qty = (int) $src->qty - $take;
            $src->save();

            $item = $src->putawayItem;
            if ($item) {
                $item->qty = max((int) $item->putaway_qty, (int) $item->qty - $take);
                $item->save();

                $putaway = $item->putaway;
                if ($putaway
                    && $putaway->status === Putaway::STATUS_IN_PROGRESS
                    && $putaway->items()->get()->every(fn ($i) => (int) $i->putaway_qty >= (int) $i->qty)) {
                    $this->putawayRepository->updateStatus($putaway->id, Putaway::STATUS_COMPLETED, [
                        'completed_at' => now(),
                    ]);
                }
            }

            $remaining -= $take;
        }

        $released = $amount - $remaining;
        if ($released > 0) {
            InboundItem::where('id', $inboundItemId)
                ->update(['reserved_qty' => DB::raw('GREATEST(reserved_qty - ' . (int) $released . ', 0)')]);
        }

        return $amount - $remaining;
    }
}
