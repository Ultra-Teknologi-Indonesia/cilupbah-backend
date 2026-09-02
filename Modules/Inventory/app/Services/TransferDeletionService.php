<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use App\Exceptions\UserFacingException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Inbound\Models\Inbound;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Models\InventoryTransfer;
use Modules\Inventory\Models\Putaway;
use Modules\Inventory\Repositories\InventoryRepository;
use Modules\Inventory\Support\InventoryMovementSourceMap;

final class TransferDeletionService
{
    public function __construct(
        private readonly InventoryRepository $inventoryRepository,
    ) {}

    public function delete(InventoryTransfer $transfer): array
    {
        $inbounds = $this->lockRelatedInbounds($transfer);
        $inboundIds = $inbounds->pluck('id')->map(static fn ($id): string => (string) $id)->all();

        $this->assertNoActiveInboundParticipants($inboundIds);

        $putawayIds = $this->relatedPutawayIds($inboundIds);
        $putaways = $this->lockPutaways($putawayIds);
        $this->assertPutawaysAreNotShared($putaways, $inboundIds);

        $transactionNumbers = collect([
            $transfer->transfer_number,
            $transfer->receive_number,
        ])
            ->merge($inbounds->pluck('transaction_number'))
            ->merge($putaways->pluck('putaway_no'))
            ->filter(static fn ($number): bool => trim((string) $number) !== '')
            ->map(static fn ($number): string => (string) $number)
            ->unique()
            ->values()
            ->all();

        $movements = $this->lockRelatedMovements($transactionNumbers);
        $movementIds = $movements->pluck('id')->map(static fn ($id): string => (string) $id)->all();

        $this->assertNoDownstreamPhysicalMovements($movements, $movementIds);
        $this->restoreInventory($movements);

        if ($movementIds !== []) {
            DB::table('inventory_movements')->whereIn('id', $movementIds)->delete();
        }

        $this->deletePutaways($putaways);
        $this->deleteInbounds($inboundIds);

        $itemIds = $transfer->items
            ->pluck('item_id')
            ->filter()
            ->map(static fn ($id): string => (string) $id)
            ->unique()
            ->values()
            ->all();

        $transfer->delete();

        return [
            'item_ids' => $itemIds,
            'variant_ids' => $itemIds,
        ];
    }

    private function lockRelatedInbounds(InventoryTransfer $transfer): Collection
    {
        $referenceNumbers = collect([
            $transfer->transfer_number,
            $transfer->receive_number,
        ])
            ->filter(static fn ($number): bool => trim((string) $number) !== '')
            ->map(static fn ($number): string => (string) $number)
            ->unique()
            ->values()
            ->all();

        return Inbound::query()
            ->where(function ($query) use ($transfer, $referenceNumbers): void {
                $query
                    ->where(function ($sourceQuery) use ($transfer): void {
                        $sourceQuery
                            ->whereIn('source_type', ['transfer', 'inventory_transfer'])
                            ->where('source_id', $transfer->id);
                    })
                    ->orWhereIn('reference_number', $referenceNumbers)
                    ->orWhereIn('transaction_number', $referenceNumbers);
            })
            ->lockForUpdate()
            ->get([
                'id',
                'transaction_number',
                'reference_number',
                'source_type',
                'source_id',
                'status',
            ]);
    }

    private function assertNoActiveInboundParticipants(array $inboundIds): void
    {
        if ($inboundIds === []) {
            return;
        }

        if (DB::table('inbound_participants')
            ->whereIn('inbound_id', $inboundIds)
            ->where('status', 'ACTIVE')
            ->exists()) {
            throw new UserFacingException(
                title: 'Transfer sedang dikerjakan',
                message: 'Transfer tidak dapat dihapus karena masih ada proses penerimaan yang sedang aktif.',
                status: 422,
            );
        }
    }

    private function relatedPutawayIds(array $inboundIds): array
    {
        if ($inboundIds === []) {
            return [];
        }

        $pivotIds = DB::table('putaway_sources')
            ->whereIn('inbound_id', $inboundIds)
            ->pluck('putaway_id');

        $legacyIds = DB::table('putaways')
            ->whereIn('source_id', $inboundIds)
            ->pluck('id');

        return $pivotIds
            ->merge($legacyIds)
            ->map(static fn ($id): string => (string) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function lockPutaways(array $putawayIds): Collection
    {
        if ($putawayIds === []) {
            return collect();
        }

        return Putaway::query()
            ->whereIn('id', $putawayIds)
            ->lockForUpdate()
            ->get(['id', 'putaway_no', 'source_type', 'source_id']);
    }

    private function assertPutawaysAreNotShared(Collection $putaways, array $inboundIds): void
    {
        if ($putaways->isEmpty()) {
            return;
        }

        $putawayIds = $putaways->pluck('id')->all();
        $foreignInbound = DB::table('putaway_sources')
            ->whereIn('putaway_id', $putawayIds)
            ->whereNotIn('inbound_id', $inboundIds)
            ->exists();

        $foreignLegacySource = $putaways->contains(
            static fn (Putaway $putaway): bool => $putaway->source_type === 'INBOUND'
                && $putaway->source_id !== null
                && ! in_array((string) $putaway->source_id, $inboundIds, true),
        );

        if ($foreignInbound || $foreignLegacySource) {
            throw new UserFacingException(
                title: 'Transfer tidak dapat dihapus',
                message: 'Sebagian barang berada dalam satu penempatan bersama dokumen lain, sehingga tidak aman untuk dihapus otomatis.',
                status: 422,
            );
        }
    }

    private function lockRelatedMovements(array $transactionNumbers): Collection
    {
        if ($transactionNumbers === []) {
            return collect();
        }

        return DB::table('inventory_movements')
            ->where('qty', '!=', 0)
            ->where(function ($query) use ($transactionNumbers): void {
                $query
                    ->whereIn('transaction_number', $transactionNumbers)
                    ->orWhereIn('reference_number', $transactionNumbers);
            })
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->lockForUpdate()
            ->get([
                'id',
                'item_id',
                'location_id',
                'bin_id',
                'source',
                'qty',
                'transaction_date',
            ]);
    }

    private function assertNoDownstreamPhysicalMovements(Collection $movements, array $movementIds): void
    {
        if ($movements->isEmpty()) {
            return;
        }

        $cells = $movements->groupBy(fn (object $movement): string => $this->cellKey($movement));

        foreach ($cells as $cellMovements) {
            $first = $cellMovements
                ->sortBy(static fn (object $movement): string => (string) $movement->transaction_date.'|'.(string) $movement->id)
                ->first();

            if ($first === null) {
                continue;
            }

            $downstream = DB::table('inventory_movements')
                ->where('item_id', $first->item_id)
                ->where('location_id', $first->location_id)
                ->when(
                    $first->bin_id === null,
                    fn ($query) => $query->whereNull('bin_id'),
                    fn ($query) => $query->where('bin_id', $first->bin_id),
                )
                ->where('qty', '!=', 0)
                ->whereNotIn('source', InventoryMovementSourceMap::NON_PHYSICAL_SOURCES)
                ->whereNotIn('id', $movementIds)
                ->where(function ($after) use ($first): void {
                    $after
                        ->where('transaction_date', '>', $first->transaction_date)
                        ->orWhere(function ($sameTime) use ($first): void {
                            $sameTime
                                ->where('transaction_date', $first->transaction_date)
                                ->where('id', '>', $first->id);
                        });
                })
                ->first(['id', 'source', 'qty']);

            if ($downstream === null) {
                continue;
            }

            $sku = DB::table('product_variants')->where('id', $first->item_id)->value('sku') ?? $first->item_id;

            throw new UserFacingException(
                title: 'Transfer tidak dapat dihapus',
                message: "Stok {$sku} sudah memiliki aktivitas lain setelah transfer ini. Batalkan aktivitas berikutnya terlebih dahulu agar saldo tetap benar.",
                status: 422,
            );
        }
    }

    private function restoreInventory(Collection $movements): void
    {
        $cells = $movements->groupBy(fn (object $movement): string => $this->cellKey($movement));

        foreach ($cells as $cellMovements) {
            $first = $cellMovements->first();
            if ($first === null) {
                continue;
            }

            $netQty = (int) $cellMovements->sum('qty');
            if ($netQty === 0) {
                continue;
            }

            $inventories = Inventory::query()
                ->where('item_id', $first->item_id)
                ->where('location_id', $first->location_id)
                ->when(
                    $first->bin_id === null,
                    fn ($query) => $query->whereNull('bin_id'),
                    fn ($query) => $query->where('bin_id', $first->bin_id),
                )
                ->lockForUpdate()
                ->get();

            if ($inventories->count() > 1) {
                throw new UserFacingException(
                    title: 'Transfer tidak dapat dihapus',
                    message: 'Stok transfer memiliki lebih dari satu batch/serial pada rak yang sama, sehingga sistem tidak dapat menentukan saldo awal dengan aman.',
                    status: 422,
                );
            }

            $inventory = $inventories->first();
            $currentOnHand = (int) ($inventory?->on_hand ?? 0);
            $restoredOnHand = $currentOnHand - $netQty;

            if ($restoredOnHand < 0) {
                $sku = DB::table('product_variants')->where('id', $first->item_id)->value('sku') ?? $first->item_id;

                throw new UserFacingException(
                    title: 'Transfer tidak dapat dihapus',
                    message: "Stok {$sku} sudah tidak utuh untuk dikembalikan ke kondisi sebelum transfer.",
                    status: 422,
                );
            }

            if ($inventory === null) {
                $inventory = Inventory::create([
                    'item_id' => $first->item_id,
                    'location_id' => $first->location_id,
                    'bin_id' => $first->bin_id,
                    'batch_no' => '',
                    'serial_no' => '',
                    'on_hand' => $restoredOnHand,
                    'on_order' => 0,
                    'available' => 0,
                ]);
            } else {
                $inventory->on_hand = $restoredOnHand;
            }

            $this->inventoryRepository->updateStock($inventory);
        }
    }

    private function cellKey(object $movement): string
    {
        return implode('|', [
            (string) $movement->item_id,
            (string) $movement->location_id,
            $movement->bin_id === null ? 'NULL' : (string) $movement->bin_id,
        ]);
    }

    private function deletePutaways(Collection $putaways): void
    {
        if ($putaways->isEmpty()) {
            return;
        }

        $putawayIds = $putaways->pluck('id')->all();
        $putawayItemIds = DB::table('putaway_items')
            ->whereIn('putaway_id', $putawayIds)
            ->pluck('id')
            ->all();

        if ($putawayItemIds !== []) {
            DB::table('putaway_item_sources')->whereIn('putaway_item_id', $putawayItemIds)->delete();
            DB::table('putaway_placements')->whereIn('putaway_item_id', $putawayItemIds)->delete();
            DB::table('putaway_items')->whereIn('id', $putawayItemIds)->delete();
        }

        DB::table('putaway_sources')->whereIn('putaway_id', $putawayIds)->delete();
        DB::table('putaways')->whereIn('id', $putawayIds)->delete();
    }

    private function deleteInbounds(array $inboundIds): void
    {
        if ($inboundIds === []) {
            return;
        }

        $inboundItemIds = DB::table('inbound_items')
            ->whereIn('inbound_id', $inboundIds)
            ->pluck('id')
            ->all();

        if ($inboundItemIds !== []) {
            DB::table('inbound_receipts')->whereIn('inbound_item_id', $inboundItemIds)->delete();
        }

        DB::table('inbounds')->whereIn('id', $inboundIds)->delete();
    }
}
