<?php

namespace Modules\Inventory\Services;

use Modules\Inventory\Repositories\InventoryRepository;
use Modules\Inventory\Repositories\InventoryMovementRepository;
use Modules\Inventory\Repositories\InventoryTransferRepository;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Models\InventoryTransfer;
use Modules\Inventory\Models\BinTransfer;
use Modules\Inventory\Models\BinTransferItem;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;
use Modules\Channel\Models\ChannelShop;
use Modules\Inbound\Services\InboundService;
use Modules\Channel\Jobs\SyncStockToChannelsJob;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Inventory\Models\InventoryTransferItem;
use Modules\Inventory\Jobs\SyncTransferDraftItemJob;

class InventoryService
{
    public function __construct(
        protected InventoryRepository $inventoryRepository,
        protected InventoryMovementRepository $movementRepository,
        protected InventoryTransferRepository $transferRepository,
    ) {}

    private function notifyChannelStock(array $variantIds): void
    {
        foreach (array_values(array_unique(array_filter($variantIds))) as $variantId) {
            SyncStockToChannelsJob::dispatch($variantId);
        }
    }

    public function getStockByItem(string $itemId): Collection
    {
        return $this->inventoryRepository->getByItem($itemId);
    }

    public function getStockByLocation(string $locationId): Collection
    {
        return $this->inventoryRepository->getByLocation($locationId);
    }

    public function recalculateAverageCost(
        string $itemId,
        string $locationId,
        ?string $binId,
        float $receivedQty,
        float $receivedCostPerUnit,
        string $batchNo = '',
        string $serialNo = ''
    ): float {
        $inventory = $this->inventoryRepository->findOrCreateForUpdate(
            $itemId,
            $locationId,
            $binId,
            $batchNo,
            $serialNo,
        );

        $currentAvg = (float) ($inventory->avg_cost ?? 0);

        if ($receivedCostPerUnit <= 0 || $receivedQty <= 0) {
            return $currentAvg;
        }

        $currentOnHand = (float) $inventory->on_hand;
        $preQty = $currentOnHand - $receivedQty;

        $totalQty = $preQty + $receivedQty;
        if ($totalQty <= 0) {
            $newAvg = $receivedCostPerUnit;
        } else {
            $newAvg = (($preQty * $currentAvg) + ($receivedQty * $receivedCostPerUnit)) / $totalQty;
        }

        $inventory->avg_cost = round($newAvg, 2);
        $inventory->save();

        return (float) $inventory->avg_cost;
    }

    public function adjust(array $data): Inventory
    {
        $inventory = DB::transaction(function () use ($data) {
            $inventory = $this->inventoryRepository->findOrCreateForUpdate(
                $data['item_id'],
                $data['location_id'],
                $data['bin_id'] ?? null,
                $data['batch_no'] ?? '',
                $data['serial_no'] ?? '',
                ['expired_date' => $data['expired_date'] ?? null],
            );

            $newOnHand = $inventory->on_hand + $data['qty'];
            if ($newOnHand < 0) {
                throw new \Exception("Stok tidak mencukupi. Adjustment akan menyebabkan stok minus (on_hand: {$inventory->on_hand}, adjustment: {$data['qty']}).");
            }

            $inventory->on_hand = $newOnHand;
            $this->inventoryRepository->updateStock($inventory);

            $this->movementRepository->create([
                'item_id' => $data['item_id'],
                'location_id' => $data['location_id'],
                'bin_id' => $data['bin_id'] ?? null,
                'transaction_number' => $data['transaction_number'] ?? 'ADJ-' . Str::upper(Str::random(8)),
                'source' => 'ADJUSTMENT',
                'qty' => $data['qty'],
                'balance' => $inventory->on_hand,
                'transaction_date' => now(),
                'created_by' => $data['created_by'],
            ]);

            return $inventory->fresh();
        });

        $this->notifyChannelStock([$data['item_id']]);

        return $inventory;
    }

    public function putaway(array $data): Inventory
    {
        return DB::transaction(function () use ($data) {
            $transactionNumber = 'PUT-' . Str::upper(Str::random(8));

            $inboundInventory = $this->inventoryRepository->findExactForUpdate(
                $data['item_id'],
                $data['location_id'],
                $data['source_bin_id'],
                $data['batch_no'] ?? '',
                $data['serial_no'] ?? ''
            );

            if (!$inboundInventory || $inboundInventory->on_hand < $data['qty']) {
                $current = $inboundInventory ? $inboundInventory->on_hand : 0;
                throw new \Exception("Stok di bin inbound tidak mencukupi (tersedia: {$current}, diminta: {$data['qty']}).");
            }

            $inboundInventory->on_hand -= $data['qty'];
            $this->inventoryRepository->updateStock($inboundInventory);

            $this->movementRepository->create([
                'item_id' => $data['item_id'],
                'location_id' => $data['location_id'],
                'bin_id' => $data['source_bin_id'],
                'transaction_number' => $transactionNumber,
                'source' => 'PUTAWAY_OUT',
                'qty' => -$data['qty'],
                'balance' => $inboundInventory->on_hand,
                'transaction_date' => now(),
                'created_by' => $data['created_by'],
            ]);

            $destInventory = $this->inventoryRepository->findOrCreateForUpdate(
                $data['item_id'],
                $data['location_id'],
                $data['destination_bin_id'],
                $data['batch_no'] ?? '',
                $data['serial_no'] ?? '',
                ['expired_date' => $data['expired_date'] ?? null],
            );

            $destInventory->on_hand += $data['qty'];
            $this->inventoryRepository->updateStock($destInventory);

            $this->movementRepository->create([
                'item_id' => $data['item_id'],
                'location_id' => $data['location_id'],
                'bin_id' => $data['destination_bin_id'],
                'transaction_number' => $transactionNumber,
                'source' => 'PUTAWAY_IN',
                'qty' => $data['qty'],
                'balance' => $destInventory->on_hand,
                'transaction_date' => now(),
                'created_by' => $data['created_by'],
            ]);

            return $destInventory->fresh();
        });
    }

    /**
     * Reverse a two-bin stock move (koreksi salah scan).
     *
     * Memindahkan `qty` kembali dari `from_bin_id` (rak yang saat ini menampung stok,
     * biasanya bin tujuan scan) ke `to_bin_id` (rak asal scan). Mencatat 2 movement
     * dengan `source` reversal sebagai jejak audit; movement forward TIDAK dihapus.
     *
     * Dipakai bersama oleh koreksi Putaway (#1) dan Pindah Bin (#6).
     *
     * required: item_id, location_id, from_bin_id, to_bin_id, qty, source, transaction_number, created_by
     * optional: batch_no, serial_no
     */
    public function reverseBinMove(array $data): void
    {
        DB::transaction(function () use ($data) {
            $qty = (int) $data['qty'];
            if ($qty <= 0) {
                throw new \Exception('Qty koreksi harus lebih dari 0.');
            }

            if (empty($data['from_bin_id']) || empty($data['to_bin_id'])) {
                throw new \Exception('Rak asal dan rak tujuan koreksi wajib diisi.');
            }

            $from = $this->inventoryRepository->findExactForUpdate(
                $data['item_id'],
                $data['location_id'],
                $data['from_bin_id'],
                $data['batch_no'] ?? '',
                $data['serial_no'] ?? '',
            );

            if (! $from || $from->on_hand < $qty) {
                $current = $from ? $from->on_hand : 0;
                throw new \Exception("Stok di rak tujuan tidak cukup untuk dikoreksi (tersedia: {$current}, diminta: {$qty}). Kemungkinan sudah dipakai proses lain.");
            }

            $unitCost = (float) ($from->avg_cost ?? 0);

            $from->on_hand -= $qty;
            $this->inventoryRepository->updateStock($from);

            $this->movementRepository->create([
                'item_id'            => $data['item_id'],
                'location_id'        => $data['location_id'],
                'bin_id'             => $data['from_bin_id'],
                'transaction_number' => $data['transaction_number'],
                'source'             => $data['source'],
                'qty'                => -$qty,
                'balance'            => $from->on_hand,
                'cost_per_unit'      => $unitCost > 0 ? $unitCost : null,
                'total_cost'         => $unitCost > 0 ? round($unitCost * $qty, 2) : null,
                'transaction_date'   => now(),
                'created_by'         => $data['created_by'],
            ]);

            $to = $this->inventoryRepository->findOrCreateForUpdate(
                $data['item_id'],
                $data['location_id'],
                $data['to_bin_id'],
                $data['batch_no'] ?? '',
                $data['serial_no'] ?? '',
            );

            $preOnHand = (float) $to->on_hand;
            $preAvg = (float) ($to->avg_cost ?? 0);

            $to->on_hand += $qty;

            if ($unitCost > 0) {
                $newTotal = $preOnHand + $qty;
                $to->avg_cost = $newTotal > 0
                    ? round((($preOnHand * $preAvg) + ($qty * $unitCost)) / $newTotal, 2)
                    : $unitCost;
            }

            $this->inventoryRepository->updateStock($to);

            $this->movementRepository->create([
                'item_id'            => $data['item_id'],
                'location_id'        => $data['location_id'],
                'bin_id'             => $data['to_bin_id'],
                'transaction_number' => $data['transaction_number'],
                'source'             => $data['source'],
                'qty'                => $qty,
                'balance'            => $to->on_hand,
                'cost_per_unit'      => $unitCost > 0 ? $unitCost : null,
                'total_cost'         => $unitCost > 0 ? round($unitCost * $qty, 2) : null,
                'transaction_date'   => now(),
                'created_by'         => $data['created_by'],
            ]);
        });

        $this->notifyChannelStock([$data['item_id']]);
    }

    /**
     * Kembalikan stok hasil pick yang salah scan ke rak asalnya (koreksi Picking #4).
     * Menaikkan on_hand + reserved di bin, mencatat movement PICKING_REVERSAL.
     *
     * required: item_id, location_id, bin_id, qty, transaction_number, created_by
     */
    public function reversePick(array $data): void
    {
        DB::transaction(function () use ($data) {
            $qty = (int) $data['qty'];
            if ($qty <= 0) {
                throw new \Exception('Qty koreksi harus lebih dari 0.');
            }

            $inventory = $this->inventoryRepository->findOrCreateForUpdate(
                $data['item_id'],
                $data['location_id'],
                $data['bin_id'],
                $data['batch_no'] ?? '',
                $data['serial_no'] ?? '',
            );

            $inventory->on_hand += $qty;
            $inventory->reserved += $qty;
            $inventory->recalculateAvailable();
            $this->inventoryRepository->updateStock($inventory);

            $this->movementRepository->create([
                'item_id'            => $data['item_id'],
                'location_id'        => $data['location_id'],
                'bin_id'             => $data['bin_id'],
                'transaction_number' => $data['transaction_number'],
                'source'             => 'PICKING_REVERSAL',
                'qty'                => $qty,
                'balance'            => $inventory->on_hand,
                'transaction_date'   => now(),
                'created_by'         => $data['created_by'],
            ]);
        });

        $this->notifyChannelStock([$data['item_id']]);
    }

    public function binTransfer(array $data): BinTransfer
    {
        $items = $this->normalizeBinTransferItems($data);

        if (empty($items)) {
            throw new \Exception('Minimal 1 produk harus ditransfer.');
        }

        foreach ($items as $idx => $it) {
            if (empty($it['source_bin_id']) || empty($it['destination_bin_id'])) {
                throw new \Exception('Setiap baris produk wajib punya rak asal dan rak tujuan.');
            }
            if ($it['source_bin_id'] === $it['destination_bin_id']) {
                throw new \Exception('Rak asal dan rak tujuan harus berbeda pada baris ke-' . ($idx + 1) . '.');
            }
        }

        $variantIds = [];

        $binTransfer = DB::transaction(function () use ($data, $items, &$variantIds) {
            $transferNumber = $data['transfer_number'] ?? $this->generateBinTransferNumber();

            $header = BinTransfer::create([
                'transfer_number' => $transferNumber,
                'location_id'     => $data['location_id'],
                'transfer_date'   => $data['transfer_date'] ?? now()->toDateString(),
                'created_by'      => $data['created_by'],
                'notes'           => $data['notes'] ?? null,
            ]);

            foreach ($items as $itemData) {
                $qty = (int) $itemData['qty'];
                if ($qty <= 0) {
                    throw new \Exception('Qty setiap produk harus lebih dari 0.');
                }

                $sourceBinId = $itemData['source_bin_id'];
                $destBinId = $itemData['destination_bin_id'];

                $source = $this->inventoryRepository->findExactForUpdate(
                    $itemData['item_id'],
                    $data['location_id'],
                    $sourceBinId,
                    $itemData['batch_no'] ?? '',
                    $itemData['serial_no'] ?? '',
                );

                if (! $source || $source->on_hand < $qty) {
                    $current = $source ? $source->on_hand : 0;
                    throw new \Exception("Stok di rak asal tidak mencukupi untuk produk {$itemData['item_id']} (tersedia: {$current}, diminta: {$qty}).");
                }

                $unitCost = (float) ($source->avg_cost ?? 0);

                $source->on_hand -= $qty;
                $this->inventoryRepository->updateStock($source);

                $this->movementRepository->create([
                    'item_id'            => $itemData['item_id'],
                    'location_id'        => $data['location_id'],
                    'bin_id'             => $sourceBinId,
                    'transaction_number' => $transferNumber,
                    'source'             => 'BIN_TRANSFER_OUT',
                    'qty'                => -$qty,
                    'balance'            => $source->on_hand,
                    'cost_per_unit'      => $unitCost > 0 ? $unitCost : null,
                    'total_cost'         => $unitCost > 0 ? round($unitCost * $qty, 2) : null,
                    'transaction_date'   => now(),
                    'created_by'         => $data['created_by'],
                ]);

                $dest = $this->inventoryRepository->findOrCreateForUpdate(
                    $itemData['item_id'],
                    $data['location_id'],
                    $destBinId,
                    $itemData['batch_no'] ?? '',
                    $itemData['serial_no'] ?? '',
                    ['expired_date' => $itemData['expired_date'] ?? null],
                );

                $dest->on_hand += $qty;
                $this->inventoryRepository->updateStock($dest);

                if ($unitCost > 0) {
                    $this->recalculateAverageCost(
                        $itemData['item_id'],
                        $data['location_id'],
                        $destBinId,
                        (float) $qty,
                        $unitCost,
                        $itemData['batch_no'] ?? '',
                        $itemData['serial_no'] ?? '',
                    );
                }

                $this->movementRepository->create([
                    'item_id'            => $itemData['item_id'],
                    'location_id'        => $data['location_id'],
                    'bin_id'             => $destBinId,
                    'transaction_number' => $transferNumber,
                    'source'             => 'BIN_TRANSFER_IN',
                    'qty'                => $qty,
                    'balance'            => $dest->on_hand,
                    'cost_per_unit'      => $unitCost > 0 ? $unitCost : null,
                    'total_cost'         => $unitCost > 0 ? round($unitCost * $qty, 2) : null,
                    'transaction_date'   => now(),
                    'created_by'         => $data['created_by'],
                ]);

                BinTransferItem::create([
                    'bin_transfer_id'    => $header->id,
                    'item_id'            => $itemData['item_id'],
                    'source_bin_id'      => $sourceBinId,
                    'destination_bin_id' => $destBinId,
                    'qty'                => $qty,
                    'batch_no'           => $itemData['batch_no'] ?? null,
                    'serial_no'          => $itemData['serial_no'] ?? null,
                    'expired_date'       => $itemData['expired_date'] ?? null,
                    'notes'              => $itemData['notes'] ?? null,
                ]);

                $variantIds[] = $itemData['item_id'];
            }

            return $header->fresh(['items']);
        });

        $this->notifyChannelStock($variantIds);

        return $binTransfer;
    }

    /**
     * Koreksi salah scan Pindah Bin: kembalikan (sebagian/seluruh) stok satu baris bin transfer
     * dari rak tujuan kembali ke rak asal, lalu kurangi/hapus baris tersebut.
     */
    public function reverseBinTransferItem(string $binTransferId, string $itemRowId, ?int $qty, string $userId): BinTransfer
    {
        return $this->reverseBinTransferItems($binTransferId, [
            ['item_id' => $itemRowId, 'qty' => $qty],
        ], $userId);
    }

    /**
     * Koreksi massal: kembalikan beberapa baris bin transfer sekaligus dalam 1 transaksi.
     * $items = [['item_id' => <bin_transfer_item_id>, 'qty' => ?int], ...]
     */
    public function reverseBinTransferItems(string $binTransferId, array $items, string $userId): BinTransfer
    {
        if (empty($items)) {
            throw new \Exception('Tidak ada baris yang dipilih untuk dikoreksi.');
        }

        return DB::transaction(function () use ($binTransferId, $items, $userId) {
            $header = BinTransfer::where('id', $binTransferId)->lockForUpdate()->first();

            if (! $header) {
                throw new \Exception('Dokumen Pindah Bin tidak ditemukan.');
            }

            foreach ($items as $entry) {
                $rowId = $entry['item_id'] ?? null;
                $qty = $entry['qty'] ?? null;

                if (! $rowId) {
                    throw new \Exception('item_id (baris) wajib diisi.');
                }

                $row = BinTransferItem::where('id', $rowId)
                    ->where('bin_transfer_id', $binTransferId)
                    ->lockForUpdate()
                    ->first();

                if (! $row) {
                    throw new \Exception('Baris Pindah Bin tidak ditemukan.');
                }

                $qtyRev = $qty ?? (int) $row->qty;

                if ($qtyRev <= 0 || $qtyRev > (int) $row->qty) {
                    throw new \Exception("Qty koreksi tidak valid (maksimal {$row->qty}).");
                }

                // Balik stok: dari rak tujuan kembali ke rak asal.
                $this->reverseBinMove([
                    'item_id'            => $row->item_id,
                    'location_id'        => $header->location_id,
                    'from_bin_id'        => $row->destination_bin_id,
                    'to_bin_id'          => $row->source_bin_id,
                    'qty'                => $qtyRev,
                    'batch_no'           => $row->batch_no ?? '',
                    'serial_no'          => $row->serial_no ?? '',
                    'source'             => 'BIN_TRANSFER_REVERSAL',
                    'transaction_number' => $header->transfer_number . '-KOREKSI',
                    'created_by'         => "user:{$userId}",
                ]);

                if ($qtyRev >= (int) $row->qty) {
                    $row->delete();
                } else {
                    $row->decrement('qty', $qtyRev);
                }
            }

            return BinTransfer::with('items')->find($binTransferId);
        });
    }

    protected function normalizeBinTransferItems(array $data): array
    {
        if (isset($data['items']) && is_array($data['items'])) {
            return array_values($data['items']);
        }

        if (isset($data['item_id']) && isset($data['qty'])) {
            return [[
                'item_id'            => $data['item_id'],
                'source_bin_id'      => $data['source_bin_id'] ?? null,
                'destination_bin_id' => $data['destination_bin_id'] ?? null,
                'qty'                => $data['qty'],
                'batch_no'           => $data['batch_no'] ?? null,
                'serial_no'          => $data['serial_no'] ?? null,
                'expired_date'       => $data['expired_date'] ?? null,
                'notes'              => $data['notes_item'] ?? null,
            ]];
        }

        return [];
    }

    protected function generateBinTransferNumber(): string
    {
        $seqId = DB::table('bin_transfer_sequences')->insertGetId([
            'created_at' => now(),
        ]);

        return 'TRFI-' . str_pad((string) $seqId, 9, '0', STR_PAD_LEFT);
    }

    public function getBinTransfers(array $filters = [], int $perPage = 10)
    {
        $query = BinTransfer::query()
            ->with(['location:id,location_name'])
            ->withCount('items');

        if (! empty($filters['q'])) {
            $query->where('transfer_number', 'like', '%' . $filters['q'] . '%');
        }

        if (! empty($filters['location_id'])) {
            $query->where('location_id', $filters['location_id']);
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('transfer_date', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->whereDate('transfer_date', '<=', $filters['date_to']);
        }

        return $query->orderByDesc('transfer_date')
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    public function getBinTransfer(string $id): ?BinTransfer
    {
        return BinTransfer::with([
            'location:id,location_name,location_code',
            'items.product',
            'items.product.product:id,name',
            'items.product.media' => fn ($q) => $q->orderByDesc('is_primary')->orderBy('sort_order'),
            'items.product.product.media' => fn ($q) => $q->orderByDesc('is_primary')->orderBy('sort_order'),
            'items.sourceBin:id,bin_final_code,location_id',
            'items.destinationBin:id,bin_final_code,location_id',
        ])->find($id);
    }

    public function updateBinTransferMetadata(string $id, array $data): BinTransfer
    {
        $transfer = BinTransfer::find($id);
        if (! $transfer) {
            throw new \Exception('Transfer internal tidak ditemukan.');
        }

        $updatable = array_intersect_key($data, array_flip(['transfer_date', 'created_by', 'notes']));
        if (empty($updatable)) {
            throw new \Exception('Tidak ada perubahan metadata.');
        }

        $transfer->update($updatable);

        return $transfer->fresh(['items', 'location']);
    }

    public function getStockItems(int $limit = 10)
    {
        return $this->inventoryRepository->getStockItems($limit);
    }

    public function getActiveChannels(): array
    {
        return ChannelShop::with('channel:id,code,name')
            ->where('is_active', true)
            ->get()
            ->map(fn ($shop) => [
                'channel_id' => $shop->channel_id,
                'channel_code' => $shop->channel?->code,
                'channel_name' => $shop->channel?->name,
                'store_name' => $shop->shop_name,
                'store_id' => $shop->shop_id,
            ])
            ->values()
            ->toArray();
    }

    public function getActiveLocations(): array
    {
        return Location::where('is_active', true)
            ->where('location_code', '!=', Location::SYSTEM_TRANSIT_CODE)
            ->select('id', 'location_name')
            ->get()
            ->map(fn ($loc) => [
                'location_id' => $loc->id,
                'location_name' => $loc->location_name,
            ])
            ->values()
            ->toArray();
    }

    public function getAllPaginated(int $limit = 10)
    {
        return $this->inventoryRepository->getAllPaginated($limit);
    }

    public function getHistoryPaginated(int $limit = 10)
    {
        return $this->movementRepository->getHistoryPaginated($limit);
    }

    public function transferOut(array $data): InventoryTransfer
    {
        $transfer = DB::transaction(function () use ($data) {
            [$transitLocationId, $transitBinId] = $this->resolveTransitLocation();
            $transferNumber = 'TRFO-' . now()->format('Ymd') . '-' . Str::upper(Str::random(4));

            $transfer = $this->transferRepository->create([
                'transfer_number'          => $transferNumber,
                'source_location_id'       => $data['source_location_id'],
                'destination_location_id'  => $data['destination_location_id'],
                'status'                   => InventoryTransfer::STATUS_IN_TRANSIT,
                'notes'                    => $data['notes'] ?? null,
                'created_by'               => $data['created_by'],
            ]);

            foreach ($data['items'] as $itemData) {
                $this->transferRepository->createItem([
                    'inventory_transfer_id' => $transfer->id,
                    'item_id'               => $itemData['item_id'],
                    'qty'                   => $itemData['qty'],
                    'source_bin_id'         => $itemData['source_bin_id'] ?? null,
                    'destination_bin_id'    => $itemData['destination_bin_id'] ?? null,
                    'batch_no'              => $itemData['batch_no'] ?? '',
                    'serial_no'             => $itemData['serial_no'] ?? '',
                ]);

                $sourceInventory = $this->inventoryRepository->findExactForUpdate(
                    $itemData['item_id'],
                    $data['source_location_id'],
                    $itemData['source_bin_id'] ?? null,
                    $itemData['batch_no'] ?? '',
                    $itemData['serial_no'] ?? ''
                );

                if (! $sourceInventory || $sourceInventory->available < $itemData['qty']) {
                    $current = $sourceInventory ? $sourceInventory->available : 0;
                    throw new \Exception("Stok tidak mencukupi di lokasi asal (tersedia: {$current}, diminta: {$itemData['qty']}).");
                }

                $sourceInventory->on_hand -= $itemData['qty'];
                $this->inventoryRepository->updateStock($sourceInventory);

                $this->movementRepository->create([
                    'item_id'            => $itemData['item_id'],
                    'location_id'        => $data['source_location_id'],
                    'bin_id'             => $itemData['source_bin_id'] ?? null,
                    'transaction_number' => $transferNumber,
                    'source'             => 'TRANSFER_OUT',
                    'qty'                => -$itemData['qty'],
                    'balance'            => $sourceInventory->on_hand,
                    'transaction_date'   => now(),
                    'created_by'         => $data['created_by'],
                ]);

                $transitInventory = $this->inventoryRepository->findOrCreateForUpdate(
                    $itemData['item_id'],
                    $transitLocationId,
                    $transitBinId,
                    $itemData['batch_no'] ?? '',
                    $itemData['serial_no'] ?? ''
                );

                $transitInventory->on_hand += $itemData['qty'];
                $this->inventoryRepository->updateStock($transitInventory);

                $this->movementRepository->create([
                    'item_id'            => $itemData['item_id'],
                    'location_id'        => $transitLocationId,
                    'bin_id'             => $transitBinId,
                    'transaction_number' => $transferNumber,
                    'source'             => 'TRANSIT_IN',
                    'qty'                => $itemData['qty'],
                    'balance'            => $transitInventory->on_hand,
                    'transaction_date'   => now(),
                    'created_by'         => $data['created_by'],
                ]);
            }

            return $this->transferRepository->findById($transfer->id);
        });

        $this->notifyChannelStock(array_column($data['items'], 'item_id'));

        return $transfer;
    }

    public function approveTransfer(string $transferId, array $data): InventoryTransfer
    {
        return DB::transaction(function () use ($transferId, $data) {
            $transfer = $this->transferRepository->findByIdForUpdate($transferId);

            if (! $transfer) {
                throw new \Exception('Transfer tidak ditemukan.');
            }

            if ($transfer->status !== InventoryTransfer::STATUS_DRAFT) {
                throw new \Exception("Transfer berstatus {$transfer->status}, hanya draft yang bisa di-approve.");
            }

            $transfer->update([
                'status'      => InventoryTransfer::STATUS_APPROVED,
                'approved_by' => $data['approved_by'],
                'assigned_to' => $data['assigned_to'] ?? null,
                'approved_at' => now(),
            ]);

            return $this->transferRepository->findById($transferId);
        });
    }

    public function cancelTransfer(string $transferId, array $data): InventoryTransfer
    {
        $transfer = DB::transaction(function () use ($transferId, $data) {
            $transfer = $this->transferRepository->findByIdForUpdate($transferId);

            if (! $transfer) {
                throw new \Exception('Transfer tidak ditemukan.');
            }

            if (! in_array($transfer->status, [InventoryTransfer::STATUS_DRAFT, InventoryTransfer::STATUS_PENDING, InventoryTransfer::STATUS_APPROVED])) {
                throw new \Exception("Transfer berstatus {$transfer->status}, hanya bisa dibatalkan sebelum dikirim.");
            }

            foreach ($transfer->items as $item) {
                $sourceInventory = $this->inventoryRepository->findExactForUpdate(
                    $item->item_id,
                    $transfer->source_location_id,
                    $item->source_bin_id,
                    $item->batch_no,
                    $item->serial_no,
                );

                if ($sourceInventory) {
                    $sourceInventory->reserved -= $item->qty;
                    $this->inventoryRepository->updateStock($sourceInventory);
                }
            }

            $transfer->update([
                'status'        => InventoryTransfer::STATUS_CANCELLED,
                'cancelled_by'  => $data['cancelled_by'],
                'cancel_reason' => $data['cancel_reason'] ?? null,
                'cancelled_at'  => now(),
            ]);

            return $this->transferRepository->findById($transferId);
        });

        $this->notifyChannelStock($transfer->items->pluck('item_id')->all());

        return $transfer;
    }

    public function shipTransfer(string $transferId, array $data): InventoryTransfer
    {
        $transfer = DB::transaction(function () use ($transferId, $data) {
            $transfer = $this->transferRepository->findByIdForUpdate($transferId);

            if (! $transfer) {
                throw new \Exception('Transfer tidak ditemukan.');
            }

            if ($transfer->status !== InventoryTransfer::STATUS_APPROVED) {
                throw new \Exception("Transfer berstatus {$transfer->status}, tidak bisa dikirim.");
            }

            foreach ($transfer->items as $item) {
                $sourceInventory = $this->inventoryRepository->findExactForUpdate(
                    $item->item_id,
                    $transfer->source_location_id,
                    $item->source_bin_id,
                    $item->batch_no,
                    $item->serial_no,
                );

                if (! $sourceInventory) {
                    throw new \Exception("Stok tidak ditemukan untuk item {$item->item_id}.");
                }

                $sourceInventory->reserved -= $item->qty;
                $sourceInventory->on_hand -= $item->qty;
                $this->inventoryRepository->updateStock($sourceInventory);

                $this->movementRepository->create([
                    'item_id'            => $item->item_id,
                    'location_id'        => $transfer->source_location_id,
                    'bin_id'             => $item->source_bin_id,
                    'transaction_number' => $transfer->transfer_number,
                    'source'             => 'TRANSFER_OUT',
                    'qty'                => -$item->qty,
                    'balance'            => $sourceInventory->on_hand,
                    'transaction_date'   => now(),
                    'created_by'         => $data['shipped_by'] ?? $transfer->assigned_to,
                ]);
            }

            $transfer->update([
                'status'     => InventoryTransfer::STATUS_IN_TRANSIT,
                'shipped_at' => now(),
            ]);

            return $this->transferRepository->findById($transferId);
        });

        $this->notifyChannelStock($transfer->items->pluck('item_id')->all());

        return $transfer;
    }

    public function transferIn(string $transferId, array $data): InventoryTransfer
    {
        $transfer = DB::transaction(function () use ($transferId, $data) {
            $transfer = $this->transferRepository->findByIdForUpdate($transferId);

            if (! $transfer) {
                throw new \Exception('Transfer tidak ditemukan.');
            }

            if (! in_array($transfer->status, [InventoryTransfer::STATUS_IN_TRANSIT, InventoryTransfer::STATUS_CHECKING])) {
                throw new \Exception("Transfer berstatus {$transfer->status}, tidak bisa di-receive.");
            }

            $receivedItems = collect($data['items'] ?? []);
            [$transitLocationId, $transitBinId] = $this->resolveTransitLocation();

            foreach ($transfer->items as $item) {
                $receivedData = $receivedItems->firstWhere('item_id', $item->item_id);
                $receivedQty  = $receivedData['received_qty'] ?? $item->qty;
                $rejectedQty  = $receivedData['rejected_qty'] ?? 0;
                $condition    = $receivedData['condition'] ?? 'GOOD';
                $itemNotes    = $receivedData['notes'] ?? null;

                if ($receivedQty > $item->qty) {
                    throw new \Exception("Qty diterima ({$receivedQty}) melebihi qty kirim ({$item->qty}).");
                }

                $transitInventory = $this->inventoryRepository->findExactForUpdate(
                    $item->item_id,
                    $transitLocationId,
                    $transitBinId,
                    $item->batch_no,
                    $item->serial_no,
                );

                if ($transitInventory) {
                    $deductQty = min($receivedQty, $transitInventory->on_hand);
                    $transitInventory->on_hand -= $deductQty;
                    $this->inventoryRepository->updateStock($transitInventory);

                    $this->movementRepository->create([
                        'item_id'            => $item->item_id,
                        'location_id'        => $transitLocationId,
                        'bin_id'             => $transitBinId,
                        'transaction_number' => $transfer->transfer_number,
                        'source'             => 'TRANSIT_OUT',
                        'qty'                => -$deductQty,
                        'balance'            => $transitInventory->on_hand,
                        'transaction_date'   => now(),
                        'created_by'         => $data['received_by'],
                    ]);
                }

                $item->update([
                    'received_qty' => $receivedQty,
                    'rejected_qty' => $rejectedQty,
                    'condition'    => $condition,
                    'item_notes'   => $itemNotes,
                ]);

                if ($receivedQty > 0) {
                    $destInventory = $this->inventoryRepository->findOrCreateForUpdate(
                        $item->item_id,
                        $transfer->destination_location_id,
                        $item->destination_bin_id,
                        $item->batch_no,
                        $item->serial_no,
                    );

                    $destInventory->on_hand += $receivedQty;
                    $this->inventoryRepository->updateStock($destInventory);

                    $this->movementRepository->create([
                        'item_id'            => $item->item_id,
                        'location_id'        => $transfer->destination_location_id,
                        'bin_id'             => $item->destination_bin_id,
                        'transaction_number' => $transfer->transfer_number,
                        'source'             => 'TRANSFER_IN',
                        'qty'                => $receivedQty,
                        'balance'            => $destInventory->on_hand,
                        'transaction_date'   => now(),
                        'created_by'         => $data['received_by'],
                    ]);
                }
            }

            $receiveNumber = 'TRFI-' . now()->format('Ymd') . '-' . Str::upper(Str::random(4));

            $updateData = [
                'status'         => InventoryTransfer::STATUS_RECEIVED,
                'receive_number' => $receiveNumber,
                'received_by'    => $data['received_by'],
                'received_at'    => now(),
            ];
            if (! empty($data['assigned_to'])) {
                $updateData['assigned_to'] = $data['assigned_to'];
            }
            $transfer->update($updateData);

            $inboundService = app(InboundService::class);
            $inboundItems = $transfer->items
                ->filter(fn ($item) => $item->received_qty > 0)
                ->map(fn ($item) => [
                    'item_id'      => $item->item_id,
                    'expected_qty' => $item->received_qty,
                ])->values()->toArray();

            if (! empty($inboundItems)) {
                $inboundService->receiveFromTransfer([
                    'location_id'      => $transfer->destination_location_id,
                    'reference_number' => $receiveNumber,
                    'source_id'        => $transfer->id,
                    'expected_date'    => now()->toDateString(),
                    'created_by'       => $data['received_by'],
                    'items'            => $inboundItems,
                ]);
            }

            if (! empty($data['assigned_to'])) {
                $assignedUser = \App\Models\User::where('name', $data['assigned_to'])->first();
                if ($assignedUser) {
                    $putawayService = app(PutawayService::class);
                    $defaultBin = LocationBin::where('location_id', $transfer->destination_location_id)->first();

                    $putawayItems = $transfer->items
                        ->filter(fn ($item) => $item->received_qty > 0)
                        ->map(fn ($item) => [
                            'item_id'          => $item->item_id,
                            'source_bin_id'    => $defaultBin?->id ?? $item->destination_bin_id,
                            'destination_bin_id' => null,
                            'qty'              => $item->received_qty,
                            'batch_no'         => $item->batch_no,
                            'serial_no'        => $item->serial_no,
                        ])->values()->toArray();

                    if (! empty($putawayItems)) {
                        $putaway = $putawayService->create([
                            'location_id'  => $transfer->destination_location_id,
                            'source_type'  => 'TRANSFER',
                            'source_id'    => $transfer->id,
                            'notes'        => "Putaway dari transfer {$transfer->transfer_number}",
                            'created_by'   => $data['received_by'],
                            'items'        => $putawayItems,
                        ]);

                        $putawayService->assignStaff([
                            'data' => [['putaway_id' => $putaway->id, 'assigned_to' => $assignedUser->id]],
                            'performed_by' => $data['received_by'],
                        ]);
                    }
                }
            }

            return $this->transferRepository->findById($transferId);
        });

        $this->notifyChannelStock($transfer->items->pluck('item_id')->all());

        return $transfer;
    }

    public function resolveTransitLocation(): array
    {
        $transit = Location::firstOrCreate(
            ['location_code' => Location::SYSTEM_TRANSIT_CODE],
            [
                'location_name'   => 'Transit',
                'location_type'   => 'Lokasi (Non Gudang)',
                'is_warehouse'    => false,
                'is_active'       => true,
                'is_system'       => true,
                'is_locked'       => true,
            ]
        );

        $bin = LocationBin::firstOrCreate(
            ['location_id' => $transit->id, 'bin_final_code' => 'DEFAULT'],
            ['max_qty' => 0, 'is_inbound' => true]
        );

        return [$transit->id, $bin->id];
    }

    public function getTransfersPaginated(array $filters = [], int $limit = 10)
    {
        return $this->transferRepository->getTransfersPaginated($filters, $limit);
    }

    public function getTransferById(string $id): ?InventoryTransfer
    {
        return $this->transferRepository->findById($id);
    }

    public function deleteTransfer(string $id): void
    {
        $transfer = $this->transferRepository->findByIdForUpdate($id);

        if (!$transfer) {
            throw new \Exception('Transfer tidak ditemukan.');
        }

        if ($transfer->status !== InventoryTransfer::STATUS_DRAFT) {
            throw new \Exception("Hanya transfer DRAFT yang bisa dihapus (status saat ini: {$transfer->status}).");
        }

        $this->transferRepository->delete($id);
    }

    public function markTransferPrinted(string $transferId, string $printedBy): InventoryTransfer
    {
        $transfer = $this->transferRepository->findById($transferId);

        if (!$transfer) {
            throw new \Exception('Transfer tidak ditemukan.');
        }

        $transfer->update([
            'printed_by' => $printedBy,
            'printed_at' => now(),
        ]);

        return $this->transferRepository->findById($transferId);
    }

    public function splitItem(array $data): array
    {
        $result = DB::transaction(function () use ($data) {
            $transactionNumber = 'SPL-' . now()->format('Ymd') . '-' . Str::upper(Str::random(4));

            $sourceInventory = $this->inventoryRepository->findExactForUpdate(
                $data['source_item_id'],
                $data['location_id'],
                $data['bin_id'] ?? null,
            );

            if (!$sourceInventory || $sourceInventory->on_hand < $data['qty_to_split']) {
                $current = $sourceInventory ? $sourceInventory->on_hand : 0;
                throw new \Exception("Stok tidak mencukupi untuk split (tersedia: {$current}, diminta: {$data['qty_to_split']}).");
            }

            $sourceInventory->on_hand -= $data['qty_to_split'];
            $this->inventoryRepository->updateStock($sourceInventory);

            $this->movementRepository->create([
                'item_id'            => $data['source_item_id'],
                'location_id'        => $data['location_id'],
                'bin_id'             => $data['bin_id'] ?? null,
                'transaction_number' => $transactionNumber,
                'source'             => 'SPLIT_OUT',
                'qty'                => -$data['qty_to_split'],
                'balance'            => $sourceInventory->on_hand,
                'transaction_date'   => now(),
                'created_by'         => $data['created_by'],
            ]);

            $targetInventory = $this->inventoryRepository->findOrCreateForUpdate(
                $data['target_item_id'],
                $data['location_id'],
                $data['bin_id'] ?? null,
            );

            $targetInventory->on_hand += $data['split_into_qty'];
            $this->inventoryRepository->updateStock($targetInventory);

            $this->movementRepository->create([
                'item_id'            => $data['target_item_id'],
                'location_id'        => $data['location_id'],
                'bin_id'             => $data['bin_id'] ?? null,
                'transaction_number' => $transactionNumber,
                'source'             => 'SPLIT_IN',
                'qty'                => $data['split_into_qty'],
                'balance'            => $targetInventory->on_hand,
                'transaction_date'   => now(),
                'created_by'         => $data['created_by'],
            ]);

            return [
                'transaction_number' => $transactionNumber,
                'source'             => $sourceInventory->fresh(),
                'target'             => $targetInventory->fresh(),
            ];
        });

        $this->notifyChannelStock([$data['source_item_id'], $data['target_item_id']]);

        return $result;
    }

    public function createDraft(array $data): InventoryTransfer
    {
        $transferNumber = 'TRFO-' . now()->format('Ymd') . '-' . Str::upper(Str::random(4));

        $transfer = $this->transferRepository->create([
            'transfer_number' => $transferNumber,
            'source_location_id' => $data['source_location_id'] ?? null,
            'destination_location_id' => $data['destination_location_id'] ?? null,
            'status' => InventoryTransfer::STATUS_DRAFT,
            'notes' => $data['notes'] ?? null,
            'created_by' => $data['created_by'],
        ]);

        return $this->transferRepository->findById($transfer->id);
    }

    public function submitDraft(string $transferId): InventoryTransfer
    {
        return DB::transaction(function () use ($transferId) {
            $transfer = $this->transferRepository->findByIdForUpdate($transferId);

            if (! $transfer) {
                throw new \Exception('Transfer tidak ditemukan.');
            }

            if ($transfer->status !== InventoryTransfer::STATUS_DRAFT) {
                throw new \Exception("Hanya transfer DRAFT yang bisa diajukan.");
            }

            if (! $transfer->source_location_id || ! $transfer->destination_location_id) {
                throw new \Exception("Gudang asal dan tujuan harus diisi.");
            }

            if ($transfer->items->isEmpty()) {
                throw new \Exception("Minimal harus ada 1 barang untuk diajukan.");
            }

            foreach ($transfer->items as $item) {
                $sourceInventory = $this->inventoryRepository->findExactForUpdate(
                    $item->item_id,
                    $transfer->source_location_id,
                    $item->source_bin_id,
                    $item->batch_no,
                    $item->serial_no,
                );

                if (! $sourceInventory) {
                    throw new \Exception("Stok tidak ditemukan untuk item {$item->item_id}.");
                }

                $sourceInventory->reserved -= $item->qty;
                $sourceInventory->on_hand -= $item->qty;
                $this->inventoryRepository->updateStock($sourceInventory);

                $this->movementRepository->create([
                    'item_id'            => $item->item_id,
                    'location_id'        => $transfer->source_location_id,
                    'bin_id'             => $item->source_bin_id,
                    'transaction_number' => $transfer->transfer_number,
                    'source'             => 'TRANSFER_OUT',
                    'qty'                => -$item->qty,
                    'balance'            => $sourceInventory->on_hand,
                    'transaction_date'   => now(),
                    'created_by'         => $transfer->created_by,
                ]);
            }

            $transfer->update([
                'status'    => InventoryTransfer::STATUS_IN_TRANSIT,
                'shipped_at' => now(),
            ]);

            return $this->transferRepository->findById($transferId);
        });
    }

    public function updateDraft(string $transferId, array $data): InventoryTransfer
    {
        $transfer = $this->transferRepository->findByIdForUpdate($transferId);

        if (!$transfer) {
            throw new \Exception('Transfer tidak ditemukan.');
        }

        if ($transfer->status !== InventoryTransfer::STATUS_DRAFT) {
            throw new \Exception("Hanya transfer DRAFT yang bisa diubah.");
        }

        $updateData = [];
        if (array_key_exists('source_location_id', $data)) {
            $updateData['source_location_id'] = $data['source_location_id'];
        }
        if (array_key_exists('destination_location_id', $data)) {
            $updateData['destination_location_id'] = $data['destination_location_id'];
        }
        if (array_key_exists('notes', $data)) {
            $updateData['notes'] = $data['notes'];
        }

        if (!empty($updateData)) {
            $transfer->update($updateData);
        }

        return $this->transferRepository->findById($transferId);
    }

    public function addDraftItem(string $transferId, array $data): InventoryTransferItem
    {
        $transfer = $this->transferRepository->findByIdForUpdate($transferId);

        if (!$transfer) {
            throw new \Exception('Transfer tidak ditemukan.');
        }

        if ($transfer->status !== InventoryTransfer::STATUS_DRAFT) {
            throw new \Exception("Hanya transfer DRAFT yang bisa ditambah item.");
        }

        $item = $this->transferRepository->createItem([
            'inventory_transfer_id' => $transfer->id,
            'item_id' => $data['item_id'],
            'qty' => $data['qty'],
            'source_bin_id' => $data['source_bin_id'] ?? null,
            'destination_bin_id' => $data['destination_bin_id'] ?? null,
            'batch_no' => $data['batch_no'] ?? '',
            'serial_no' => $data['serial_no'] ?? '',
            'sync_status' => InventoryTransferItem::SYNC_PENDING,
        ]);

        SyncTransferDraftItemJob::dispatch(
            $item->id,
            SyncTransferDraftItemJob::ACTION_RESERVE,
            $data['qty'],
            $transfer->transfer_number,
            $transfer->created_by,
        );

        return $item->load(['product:id,sku,product_id', 'sourceBin:id,bin_final_code', 'destinationBin:id,bin_final_code']);
    }

    public function updateDraftItemQty(string $transferId, string $itemId, int $newQty): InventoryTransferItem
    {
        $transfer = $this->transferRepository->findByIdForUpdate($transferId);

        if (!$transfer) {
            throw new \Exception('Transfer tidak ditemukan.');
        }

        if ($transfer->status !== InventoryTransfer::STATUS_DRAFT) {
            throw new \Exception("Hanya transfer DRAFT yang bisa diubah item-nya.");
        }

        $item = InventoryTransferItem::where('id', $itemId)
            ->where('inventory_transfer_id', $transferId)
            ->firstOrFail();

        $oldQty = $item->qty;
        $delta = $newQty - $oldQty;

        $item->update([
            'qty' => $newQty,
            'sync_status' => InventoryTransferItem::SYNC_PENDING,
        ]);

        if ($delta !== 0) {
            SyncTransferDraftItemJob::dispatch(
                $item->id,
                SyncTransferDraftItemJob::ACTION_ADJUST,
                $delta,
                $transfer->transfer_number,
                $transfer->created_by,
            );
        }

        return $item->fresh()->load(['product:id,sku,product_id', 'sourceBin:id,bin_final_code']);
    }

    public function removeDraftItem(string $transferId, string $itemId): void
    {
        $transfer = $this->transferRepository->findByIdForUpdate($transferId);

        if (!$transfer) {
            throw new \Exception('Transfer tidak ditemukan.');
        }

        if ($transfer->status !== InventoryTransfer::STATUS_DRAFT) {
            throw new \Exception("Hanya transfer DRAFT yang bisa dihapus item-nya.");
        }

        $item = InventoryTransferItem::where('id', $itemId)
            ->where('inventory_transfer_id', $transferId)
            ->firstOrFail();

        $qty = $item->qty;

        SyncTransferDraftItemJob::dispatch(
            $item->id,
            SyncTransferDraftItemJob::ACTION_RELEASE,
            $qty,
            $transfer->transfer_number,
            $transfer->created_by,
        );
    }
}
