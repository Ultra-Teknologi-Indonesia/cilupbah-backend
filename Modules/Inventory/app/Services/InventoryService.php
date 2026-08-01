<?php

namespace Modules\Inventory\Services;

use Modules\Inventory\Repositories\InventoryRepository;
use Modules\Inventory\Repositories\InventoryMovementRepository;
use Modules\Inventory\Repositories\InventoryTransferRepository;
use Modules\Inventory\Repositories\BinTransferRepository;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Models\InventoryTransfer;
use Modules\Inventory\Models\BinTransfer;
use Modules\Inventory\Models\BinTransferItem;
use Modules\Inventory\Models\BinTransferReceipt;
use Modules\Inventory\Models\BinTransferReceiptItem;
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
        protected BinTransferRepository $binTransferRepository,
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

    public function getStockItemDetail(string $itemId): \Modules\Product\Models\ProductVariant
    {
        return $this->inventoryRepository->findVariantWithStockDetail($itemId);
    }

    public function getMovementFilterOptions(): array
    {
        $base = \Modules\Inventory\Support\InventoryMovementSourceMap::filterOptions();

        $locations = $this->inventoryRepository->activeLocationsForFilters()
            ->map(fn ($l) => ['value' => (string) $l->id, 'label' => $l->location_name])
            ->values()
            ->toArray();

        $stores = $this->inventoryRepository->channelShopsForFilters()
            ->map(function ($s) {
                $channelName = $s->channel?->name ?? '—';
                return [
                    'value' => (string) $s->shop_id,
                    'label' => "{$channelName} - {$s->shop_name}",
                ];
            })
            ->values()
            ->toArray();

        return array_merge($base, ['locations' => $locations, 'stores' => $stores]);
    }

    public function deleteVariants(array $ids): int
    {
        if ($this->inventoryRepository->variantsHaveStock($ids)) {
            throw new \RuntimeException('Tidak bisa menghapus varian yang masih punya stok.');
        }

        return $this->inventoryRepository->deleteVariants($ids);
    }

    public function findVariantForSku(string $sku): ?\Modules\Product\Models\ProductVariant
    {
        return $this->inventoryRepository->findVariantBySkuOrBarcode(trim($sku));
    }

    public function buildSkuStockSummary(\Modules\Product\Models\ProductVariant $variant, ?string $locationId, string $strategy = 'default'): array
    {
        $primary = $this->inventoryRepository->findPrimaryBinStock($variant->id, $locationId);
        $onHand = $this->inventoryRepository->sumOnHandForSku($variant->id, $locationId);

        $availableBins = $this->inventoryRepository->availableBinStocks($variant->id, $locationId, $strategy)
            ->map(fn ($inv) => [
                'id'       => $inv->bin_id,
                'code'     => $inv->bin?->bin_final_code,
                'on_hand'  => (int) $inv->on_hand,
                'avg_cost' => (float) $inv->avg_cost,
            ])
            ->filter(fn ($b) => $b['code'] !== null)
            ->values()
            ->toArray();

        $variantLabel = $variant->options
            ->map(fn ($o) => $o->value)
            ->filter()
            ->implode(', ');

        return [
            'id'             => $variant->id,
            'sku'            => $variant->sku,
            'product_id'     => $variant->product_id,
            'product_name'   => $variant->product?->name,
            'variant_label'  => $variantLabel,
            'thumbnail_url'  => $this->resolveVariantThumbnail($variant),
            'on_hand'        => (int) $onHand,
            'avg_cost'       => $primary ? (float) $primary->avg_cost : 0,
            'primary_bin'    => $primary && $primary->bin ? [
                'id'       => $primary->bin_id,
                'code'     => $primary->bin->bin_final_code,
                'on_hand'  => (int) $primary->on_hand,
                'avg_cost' => (float) $primary->avg_cost,
            ] : null,
            'available_bins' => $availableBins,
        ];
    }

    public function getBinStockItems(string $binCode): ?array
    {
        $bin = $this->inventoryRepository->findBinByFinalCode($binCode);

        if (! $bin) {
            return null;
        }

        return $this->inventoryRepository->getStockByBin($bin->id)
            ->map(fn ($inv) => [
                'item_id' => $inv->item_id,
                'sku' => $inv->product?->sku,
                'product_name' => $inv->product?->product?->name,
                'bin_code' => $inv->bin?->bin_final_code ?? $binCode,
                'on_hand' => (int) $inv->on_hand,
                'on_order' => (int) $inv->on_order,
                'available' => (int) $inv->available,
            ])
            ->toArray();
    }

    public function getStockedItems(string $locationId, string $search, int $perPage, bool $includeZero = false)
    {
        $paginated = $this->inventoryRepository->getStockedItems($locationId, $search, $perPage, $includeZero);

        $paginated->getCollection()->transform(function ($variant) {
            $variationValues = ($variant->options ?? collect())
                ->map(fn ($o) => [
                    'label' => $o->attribute?->name ?? '',
                    'value' => $o->value ?? '',
                ])
                ->filter(fn ($v) => $v['value'] !== '')
                ->values()
                ->all();

            $variantLabel = collect($variationValues)
                ->map(fn ($v) => $v['value'])
                ->implode(', ');

            return [
                'item_id'          => $variant->id,
                'sku'              => $variant->sku,
                'product_id'       => $variant->product_id,
                'product_name'     => $variant->product?->name,
                'variant_label'    => $variantLabel,
                'variation_values' => $variationValues,
                'thumbnail_url'    => $this->resolveVariantThumbnail($variant),
                'total_on_hand'    => (int) ($variant->total_on_hand ?? 0),
                'sell_price'       => (float) ($variant->sell_price ?? 0),
            ];
        });

        return $paginated;
    }

    private function resolveVariantThumbnail($variant): ?string
    {
        $variantMedia = $variant->media?->firstWhere('media_type', 'image')
            ?? $variant->media?->first();
        if ($variantMedia?->url) {
            return $variantMedia->url;
        }

        $productMedia = $variant->product?->media?->firstWhere('media_type', 'image')
            ?? $variant->product?->media?->first();

        return $productMedia?->url;
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
        app(\Modules\Product\Services\BundleGuardService::class)
            ->assertNotBundle([$data['item_id'] ?? null], 'operasi stok fisik');

        $inventory = DB::transaction(function () use ($data) {
            if (! empty($data['bin_id']) && (int) $data['qty'] > 0) {
                app(\Modules\Warehouse\Services\BinOccupancyGuard::class)
                    ->assertBinFitsSku($data['bin_id'], $data['item_id']);
                app(\Modules\Warehouse\Services\SkuHomeBinGuard::class)
                    ->assertSkuFitsBin($data['location_id'], $data['item_id'], $data['bin_id']);
            }

            $inventory = $this->inventoryRepository->findOrCreateForUpdate(
                $data['item_id'],
                $data['location_id'],
                $data['bin_id'] ?? null,
                $data['batch_no'] ?? '',
                $data['serial_no'] ?? '',
                ['expired_date' => $data['expired_date'] ?? null],
            );

            $newOnHand = $inventory->on_hand + $data['qty'];
            if (! config('inventory.allow_negative_stock', true) && $newOnHand < 0) {
                throw new \Exception("Stok tidak mencukupi. Adjustment akan menyebabkan stok minus (on_hand: {$inventory->on_hand}, adjustment: {$data['qty']}).");
            }

            $inventory->on_hand = $newOnHand;
            $this->inventoryRepository->updateStock($inventory);

            $this->movementRepository->create([
                'item_id' => $data['item_id'],
                'location_id' => $data['location_id'],
                'bin_id' => $data['bin_id'] ?? null,
                'transaction_number' => $data['transaction_number'] ?? 'ADJ-' . Str::upper(Str::random(8)),
                'source' => $data['source'] ?? 'ADJUSTMENT',
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
        app(\Modules\Product\Services\BundleGuardService::class)
            ->assertNotBundle([$data['item_id'] ?? null], 'penempatan (putaway)');

        return DB::transaction(function () use ($data) {
            $transactionNumber = 'PUT-' . Str::upper(Str::random(8));

            app(\Modules\Warehouse\Services\BinOccupancyGuard::class)
                ->assertBinFitsSku($data['destination_bin_id'], $data['item_id']);

            if (empty($data['skip_home_bin_guard'])) {
                app(\Modules\Warehouse\Services\SkuHomeBinGuard::class)
                    ->assertSkuFitsBin($data['location_id'], $data['item_id'], $data['destination_bin_id']);
            }

            $inboundInventory = $this->inventoryRepository->findExactForUpdate(
                $data['item_id'],
                $data['location_id'],
                $data['source_bin_id'],
                $data['batch_no'] ?? '',
                $data['serial_no'] ?? ''
            );

            if (! $inboundInventory) {
                $inboundInventory = $this->inventoryRepository->findOrCreateForUpdate(
                    $data['item_id'],
                    $data['location_id'],
                    $data['source_bin_id'],
                    $data['batch_no'] ?? '',
                    $data['serial_no'] ?? ''
                );
            }

            if (! config('inventory.allow_negative_stock', true) && $inboundInventory->on_hand < $data['qty']) {
                throw new \Exception("Stok di bin inbound tidak mencukupi (tersedia: {$inboundInventory->on_hand}, diminta: {$data['qty']}).");
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

            app(\Modules\Warehouse\Services\BinOccupancyGuard::class)
                ->assertBinFitsSku($data['to_bin_id'], $data['item_id']);
            app(\Modules\Warehouse\Services\SkuHomeBinGuard::class)
                ->assertSkuFitsBin($data['location_id'], $data['item_id'], $data['to_bin_id']);

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
            $inventory->on_order += $qty;
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

    public function createBinTransferDraft(array $data): BinTransfer
    {
        $items = $this->normalizeBinTransferItems($data);

        if (empty($items)) {
            throw new \Exception('Minimal 1 produk harus ditransfer.');
        }

        app(\Modules\Product\Services\BundleGuardService::class)
            ->assertNotBundle(array_column($items, 'item_id'), 'transfer antar rak');

        foreach ($items as $idx => $it) {
            if (empty($it['source_bin_id'])) {
                throw new \Exception('Setiap baris produk wajib punya rak asal pada baris ke-' . ($idx + 1) . '.');
            }
            if ((int) ($it['qty'] ?? 0) <= 0) {
                throw new \Exception('Qty setiap produk harus lebih dari 0.');
            }
        }

        return DB::transaction(function () use ($data, $items) {
            $transferNumber = $data['transfer_number'] ?? $this->generateTrfoNumber();

            $header = BinTransfer::create([
                'transfer_number' => $transferNumber,
                'location_id'     => $data['location_id'],
                'status'          => BinTransfer::STATUS_BARU_DIBUAT,
                'transfer_date'   => $data['transfer_date'] ?? now()->toDateString(),
                'created_by'      => $data['created_by'],
                'notes'           => $data['notes'] ?? null,
            ]);

            foreach ($items as $itemData) {
                $qty = (int) $itemData['qty'];
                $sourceBinId = $itemData['source_bin_id'];

                $source = $this->inventoryRepository->findExactForUpdate(
                    $itemData['item_id'],
                    $data['location_id'],
                    $sourceBinId,
                    $itemData['batch_no'] ?? '',
                    $itemData['serial_no'] ?? '',
                );

                if (! $source) {
                    $source = $this->inventoryRepository->findOrCreateForUpdate(
                        $itemData['item_id'],
                        $data['location_id'],
                        $sourceBinId,
                        $itemData['batch_no'] ?? '',
                        $itemData['serial_no'] ?? '',
                    );
                }

                if (! config('inventory.allow_negative_stock', true) && $source->on_hand < $qty) {
                    throw new \Exception("Stok di rak asal tidak mencukupi untuk produk {$itemData['item_id']} (tersedia: {$source->on_hand}, diminta: {$qty}).");
                }

                BinTransferItem::create([
                    'bin_transfer_id'    => $header->id,
                    'item_id'            => $itemData['item_id'],
                    'source_bin_id'      => $sourceBinId,
                    'destination_bin_id' => null,
                    'qty'                => $qty,
                    'placed_qty'         => 0,
                    'batch_no'           => $itemData['batch_no'] ?? null,
                    'serial_no'          => $itemData['serial_no'] ?? null,
                    'expired_date'       => $itemData['expired_date'] ?? null,
                    'notes'              => $itemData['notes'] ?? null,
                ]);
            }

            return $header->fresh(['items']);
        });
    }

    public function printBinTransfer(string $id, string $printedBy): BinTransfer
    {
        $variantIds = [];

        $result = DB::transaction(function () use ($id, $printedBy, &$variantIds) {
            $header = BinTransfer::where('id', $id)->lockForUpdate()->first();

            if (! $header) {
                throw new \Exception('Transfer internal tidak ditemukan.');
            }
            if ($header->status !== BinTransfer::STATUS_BARU_DIBUAT) {
                throw new \Exception('Hanya transfer berstatus Baru Dibuat yang bisa dicetak.');
            }

            [$transitLocationId, $transitBinId] = $this->resolveTransitLocation();
            $items = $header->items()->lockForUpdate()->get();

            foreach ($items as $item) {
                $qty = (int) $item->qty;

                $source = $this->inventoryRepository->findExactForUpdate(
                    $item->item_id,
                    $header->location_id,
                    $item->source_bin_id,
                    $item->batch_no ?? '',
                    $item->serial_no ?? '',
                );

                if (! $source) {
                    $source = $this->inventoryRepository->findOrCreateForUpdate(
                        $item->item_id,
                        $header->location_id,
                        $item->source_bin_id,
                        $item->batch_no ?? '',
                        $item->serial_no ?? '',
                    );
                }

                if (! config('inventory.allow_negative_stock', true) && $source->on_hand < $qty) {
                    throw new \Exception("Stok di rak asal tidak mencukupi untuk salah satu produk (tersedia: {$source->on_hand}, diminta: {$qty}). Batalkan/ubah transfer.");
                }

                $unitCost = (float) ($source->avg_cost ?? 0);

                $source->on_hand -= $qty;
                $this->inventoryRepository->updateStock($source);

                $this->movementRepository->create([
                    'item_id'            => $item->item_id,
                    'location_id'        => $header->location_id,
                    'bin_id'             => $item->source_bin_id,
                    'transaction_number' => $header->transfer_number,
                    'source'             => 'BIN_TRANSFER_OUT',
                    'qty'                => -$qty,
                    'balance'            => $source->on_hand,
                    'cost_per_unit'      => $unitCost > 0 ? $unitCost : null,
                    'total_cost'         => $unitCost > 0 ? round($unitCost * $qty, 2) : null,
                    'transaction_date'   => now(),
                    'created_by'         => $printedBy,
                ]);

                $transit = $this->inventoryRepository->findOrCreateForUpdate(
                    $item->item_id,
                    $transitLocationId,
                    $transitBinId,
                    $item->batch_no ?? '',
                    $item->serial_no ?? '',
                    ['expired_date' => $item->expired_date ?? null],
                );

                $transit->on_hand += $qty;
                $this->inventoryRepository->updateStock($transit);

                if ($unitCost > 0) {
                    $this->recalculateAverageCost(
                        $item->item_id,
                        $transitLocationId,
                        $transitBinId,
                        (float) $qty,
                        $unitCost,
                        $item->batch_no ?? '',
                        $item->serial_no ?? '',
                    );
                }

                $this->movementRepository->create([
                    'item_id'            => $item->item_id,
                    'location_id'        => $transitLocationId,
                    'bin_id'             => $transitBinId,
                    'transaction_number' => $header->transfer_number,
                    'source'             => 'TRANSIT_IN',
                    'qty'                => $qty,
                    'balance'            => $transit->on_hand,
                    'transaction_date'   => now(),
                    'created_by'         => $printedBy,
                ]);

                $variantIds[] = $item->item_id;
            }

            $header->update([
                'status'     => BinTransfer::STATUS_SEDANG_DIJALAN,
                'printed_at' => now(),
                'printed_by' => $printedBy,
            ]);

            return $header->fresh(['items']);
        });

        $this->notifyChannelStock($variantIds);

        return $result;
    }

    public function revertBinTransferPrint(string $id, string $actor): BinTransfer
    {
        $variantIds = [];

        $result = DB::transaction(function () use ($id, $actor, &$variantIds) {
            $header = BinTransfer::where('id', $id)->lockForUpdate()->first();

            if (! $header) {
                throw new \Exception('Transfer internal tidak ditemukan.');
            }
            if ($header->status !== BinTransfer::STATUS_SEDANG_DIJALAN) {
                throw new \Exception('Hanya transfer Sedang Dijalan yang bisa dikembalikan ke Baru Dibuat.');
            }

            $items = $header->items()->lockForUpdate()->get();

            if ($items->sum('placed_qty') > 0) {
                throw new \Exception('Sudah ada penerimaan sebagian. Tidak bisa dikembalikan ke Baru Dibuat.');
            }

            [$transitLocationId, $transitBinId] = $this->resolveTransitLocation();
            $txnNumber = $header->transfer_number . '-BATAL';

            foreach ($items as $item) {
                $qty = (int) $item->qty;

                $transit = $this->inventoryRepository->findExactForUpdate(
                    $item->item_id,
                    $transitLocationId,
                    $transitBinId,
                    $item->batch_no ?? '',
                    $item->serial_no ?? '',
                );

                if (! $transit || $transit->on_hand < $qty) {
                    $current = $transit ? $transit->on_hand : 0;
                    throw new \Exception("Stok transit tidak cukup untuk dikembalikan (tersedia: {$current}, diminta: {$qty}).");
                }

                $unitCost = (float) ($transit->avg_cost ?? 0);

                $transit->on_hand -= $qty;
                $this->inventoryRepository->updateStock($transit);

                $this->movementRepository->create([
                    'item_id'            => $item->item_id,
                    'location_id'        => $transitLocationId,
                    'bin_id'             => $transitBinId,
                    'transaction_number' => $txnNumber,
                    'source'             => 'TRANSIT_OUT',
                    'qty'                => -$qty,
                    'balance'            => $transit->on_hand,
                    'transaction_date'   => now(),
                    'created_by'         => $actor,
                ]);

                $source = $this->inventoryRepository->findOrCreateForUpdate(
                    $item->item_id,
                    $header->location_id,
                    $item->source_bin_id,
                    $item->batch_no ?? '',
                    $item->serial_no ?? '',
                    ['expired_date' => $item->expired_date ?? null],
                );

                $source->on_hand += $qty;
                $this->inventoryRepository->updateStock($source);

                if ($unitCost > 0) {
                    $this->recalculateAverageCost(
                        $item->item_id,
                        $header->location_id,
                        $item->source_bin_id,
                        (float) $qty,
                        $unitCost,
                        $item->batch_no ?? '',
                        $item->serial_no ?? '',
                    );
                }

                $this->movementRepository->create([
                    'item_id'            => $item->item_id,
                    'location_id'        => $header->location_id,
                    'bin_id'             => $item->source_bin_id,
                    'transaction_number' => $txnNumber,
                    'source'             => 'BIN_TRANSFER_REVERSAL',
                    'qty'                => $qty,
                    'balance'            => $source->on_hand,
                    'cost_per_unit'      => $unitCost > 0 ? $unitCost : null,
                    'total_cost'         => $unitCost > 0 ? round($unitCost * $qty, 2) : null,
                    'transaction_date'   => now(),
                    'created_by'         => $actor,
                ]);

                $variantIds[] = $item->item_id;
            }

            $header->update([
                'status'     => BinTransfer::STATUS_BARU_DIBUAT,
                'printed_at' => null,
                'printed_by' => null,
            ]);

            return $header->fresh(['items']);
        });

        $this->notifyChannelStock($variantIds);

        return $result;
    }

    public function receiveBinTransfer(string $id, array $data): BinTransferReceipt
    {
        $variantIds = [];

        $result = DB::transaction(function () use ($id, $data, &$variantIds) {
            $header = BinTransfer::where('id', $id)->lockForUpdate()->first();

            if (! $header) {
                throw new \Exception('Transfer internal tidak ditemukan.');
            }
            if ($header->status !== BinTransfer::STATUS_SEDANG_DIJALAN) {
                throw new \Exception('Hanya transfer Sedang Dijalan yang bisa diterima.');
            }

            $lines = collect($data['items'] ?? [])->filter(fn ($l) => (int) ($l['qty'] ?? 0) > 0);
            if ($lines->isEmpty()) {
                throw new \Exception('Minimal satu baris dengan qty terima harus diisi.');
            }

            [$transitLocationId, $transitBinId] = $this->resolveTransitLocation();

            $receipt = BinTransferReceipt::create([
                'receipt_number'  => $this->generateTrfiNumber(),
                'bin_transfer_id' => $header->id,
                'location_id'     => $header->location_id,
                'received_by'     => $data['received_by'],
                'received_at'     => now(),
                'notes'           => $data['notes'] ?? null,
            ]);

            foreach ($lines as $line) {
                $rowId = $line['bin_transfer_item_id'] ?? $line['item_id'] ?? null;
                $destBinId = $line['destination_bin_id'] ?? null;
                $qtyTerima = (int) $line['qty'];

                if (! $rowId) {
                    throw new \Exception('bin_transfer_item_id wajib diisi pada setiap baris penerimaan.');
                }
                if (! $destBinId) {
                    throw new \Exception('Rak tujuan wajib diisi pada setiap baris penerimaan.');
                }

                $item = BinTransferItem::where('id', $rowId)
                    ->where('bin_transfer_id', $header->id)
                    ->lockForUpdate()
                    ->first();

                if (! $item) {
                    throw new \Exception('Baris transfer tidak ditemukan.');
                }

                $remaining = (int) $item->qty - (int) $item->placed_qty;
                if ($qtyTerima > $remaining) {
                    $item->loadMissing('product.product');
                    $name = $item->product?->product?->name
                        ?? $item->product?->sku
                        ?? $item->item_id;
                    throw new \Exception("Jumlah transfer untuk {$name} melebihi jumlah yang tersedia.");
                }

                $transit = $this->inventoryRepository->findExactForUpdate(
                    $item->item_id,
                    $transitLocationId,
                    $transitBinId,
                    $item->batch_no ?? '',
                    $item->serial_no ?? '',
                );

                if (! $transit) {
                    $transit = $this->inventoryRepository->findOrCreateForUpdate(
                        $item->item_id,
                        $transitLocationId,
                        $transitBinId,
                        $item->batch_no ?? '',
                        $item->serial_no ?? '',
                    );
                }

                if (! config('inventory.allow_negative_stock', true) && $transit->on_hand < $qtyTerima) {
                    throw new \Exception("Stok transit tidak mencukupi (tersedia: {$transit->on_hand}, diminta: {$qtyTerima}).");
                }

                $unitCost = (float) ($transit->avg_cost ?? 0);

                $transit->on_hand -= $qtyTerima;
                $this->inventoryRepository->updateStock($transit);

                $this->movementRepository->create([
                    'item_id'            => $item->item_id,
                    'location_id'        => $transitLocationId,
                    'bin_id'             => $transitBinId,
                    'transaction_number' => $receipt->receipt_number,
                    'source'             => 'TRANSIT_OUT',
                    'qty'                => -$qtyTerima,
                    'balance'            => $transit->on_hand,
                    'transaction_date'   => now(),
                    'created_by'         => $data['received_by'],
                ]);

                app(\Modules\Warehouse\Services\BinOccupancyGuard::class)
                    ->assertBinFitsSku($destBinId, $item->item_id);
                app(\Modules\Warehouse\Services\SkuHomeBinGuard::class)
                    ->assertSkuFitsBin($header->location_id, $item->item_id, $destBinId);

                $dest = $this->inventoryRepository->findOrCreateForUpdate(
                    $item->item_id,
                    $header->location_id,
                    $destBinId,
                    $item->batch_no ?? '',
                    $item->serial_no ?? '',
                    ['expired_date' => $item->expired_date ?? null],
                );

                $dest->on_hand += $qtyTerima;
                $this->inventoryRepository->updateStock($dest);

                if ($unitCost > 0) {
                    $this->recalculateAverageCost(
                        $item->item_id,
                        $header->location_id,
                        $destBinId,
                        (float) $qtyTerima,
                        $unitCost,
                        $item->batch_no ?? '',
                        $item->serial_no ?? '',
                    );
                }

                $this->movementRepository->create([
                    'item_id'            => $item->item_id,
                    'location_id'        => $header->location_id,
                    'bin_id'             => $destBinId,
                    'transaction_number' => $receipt->receipt_number,
                    'source'             => 'BIN_TRANSFER_IN',
                    'qty'                => $qtyTerima,
                    'balance'            => $dest->on_hand,
                    'cost_per_unit'      => $unitCost > 0 ? $unitCost : null,
                    'total_cost'         => $unitCost > 0 ? round($unitCost * $qtyTerima, 2) : null,
                    'transaction_date'   => now(),
                    'created_by'         => $data['received_by'],
                ]);

                BinTransferReceiptItem::create([
                    'bin_transfer_receipt_id' => $receipt->id,
                    'bin_transfer_item_id'    => $item->id,
                    'destination_bin_id'      => $destBinId,
                    'qty'                     => $qtyTerima,
                ]);

                $item->placed_qty = (int) $item->placed_qty + $qtyTerima;
                $item->destination_bin_id = $destBinId; 
                $item->save();

                $variantIds[] = $item->item_id;
            }

            $allPlaced = $header->items()->get()
                ->every(fn ($it) => (int) $it->placed_qty >= (int) $it->qty);

            if ($allPlaced) {
                $header->update(['status' => BinTransfer::STATUS_SELESAI]);
            }

            return $receipt->fresh(['items.destinationBin', 'binTransfer']);
        });

        $this->notifyChannelStock(array_values(array_unique($variantIds)));

        return $result;
    }

    public function reverseBinTransferItem(string $binTransferId, string $itemRowId, ?int $qty, string $userId): BinTransfer
    {
        return $this->reverseBinTransferItems($binTransferId, [
            ['item_id' => $itemRowId, 'qty' => $qty],
        ], $userId);
    }

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

    protected function nextBinTransferSeq(): int
    {
        return DB::table('bin_transfer_sequences')->insertGetId([
            'created_at' => now(),
        ]);
    }

    protected function generateTrfoNumber(): string
    {
        return 'TRFO-' . str_pad((string) $this->nextBinTransferSeq(), 9, '0', STR_PAD_LEFT);
    }

    public function generateTrfiNumber(): string
    {
        return 'TRFI-' . str_pad((string) $this->nextBinTransferSeq(), 9, '0', STR_PAD_LEFT);
    }

    public function getBinTransfers(array $filters = [], int $perPage = 10)
    {
        return $this->binTransferRepository->paginateTransfers($filters, $perPage);
    }

    public function getBinTransfer(string $id): ?BinTransfer
    {
        return $this->binTransferRepository->findTransfer($id);
    }

    public function getBinTransferReceipts(array $filters = [], int $perPage = 10)
    {
        return $this->binTransferRepository->paginateReceipts($filters, $perPage);
    }

    public function getBinTransferReceipt(string $id): ?BinTransferReceipt
    {
        return $this->binTransferRepository->findReceipt($id);
    }

    public function deleteBinTransferDraft(string $id): void
    {
        DB::transaction(function () use ($id) {
            $transfer = BinTransfer::where('id', $id)->lockForUpdate()->first();
            if (! $transfer) {
                throw new \Exception('Transfer internal tidak ditemukan.');
            }
            if ($transfer->status !== BinTransfer::STATUS_BARU_DIBUAT) {
                throw new \Exception('Hanya transfer Baru Dibuat yang bisa dihapus. Kembalikan dulu ke Baru Dibuat bila sedang dijalan.');
            }

            $transfer->items()->delete();
            $transfer->delete();
        });
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
        app(\Modules\Product\Services\BundleGuardService::class)
            ->assertNotBundle(array_column($data['items'] ?? [], 'item_id'), 'transfer stok');

        $transfer = DB::transaction(function () use ($data) {
            [$transitLocationId, $transitBinId] = $this->resolveTransitLocation();
            $transferNumber = $this->generateTransferNumber();

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

                if (! $sourceInventory) {
                    $sourceInventory = $this->inventoryRepository->findOrCreateForUpdate(
                        $itemData['item_id'],
                        $data['source_location_id'],
                        $itemData['source_bin_id'] ?? null,
                        $itemData['batch_no'] ?? '',
                        $itemData['serial_no'] ?? ''
                    );
                }

                if (! config('inventory.allow_negative_stock', true) && $sourceInventory->available < $itemData['qty']) {
                    throw new \Exception("Stok tidak mencukupi di lokasi asal (tersedia: {$sourceInventory->available}, diminta: {$itemData['qty']}).");
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

        $this->createTransitInbound($transfer);

        $this->notifyChannelStock(array_column($data['items'], 'item_id'));

        return $transfer;
    }

    public function approveTransfer(string $transferId, array $data): InventoryTransfer
    {
        $transfer = DB::transaction(function () use ($transferId, $data) {
            $transfer = $this->transferRepository->findByIdForUpdate($transferId);

            if (! $transfer) {
                throw new \Exception('Transfer tidak ditemukan.');
            }

            if ($transfer->status !== InventoryTransfer::STATUS_DRAFT) {
                throw new \Exception("Transfer berstatus {$transfer->status}, hanya draft yang bisa di-approve.");
            }

            if (! $transfer->source_location_id) {
                throw new \Exception('Gudang asal belum diatur, tidak bisa di-approve.');
            }

            foreach ($transfer->items()->get() as $item) {
                if (! empty($item->source_bin_id)) {
                    continue;
                }

                $this->assignAndReserveItemFifo($transfer, $item, $data['approved_by']);
            }

            $transfer->update([
                'status'      => InventoryTransfer::STATUS_APPROVED,
                'approved_by' => $data['approved_by'],
                'assigned_to' => $data['assigned_to'] ?? null,
                'approved_at' => now(),
            ]);

            return $this->transferRepository->findById($transferId);
        });

        $this->notifyChannelStock($transfer->items->pluck('item_id')->unique()->all());

        return $transfer;
    }

    protected function assignAndReserveItemFifo(InventoryTransfer $transfer, InventoryTransferItem $item, string $actor): void
    {
        $needed = (int) $item->qty;
        if ($needed <= 0) {
            return;
        }

        $rows = Inventory::where('item_id', $item->item_id)
            ->where('location_id', $transfer->source_location_id)
            ->whereNotNull('bin_id')
            ->where('available', '>', 0)
            ->orderBy('created_at')
            ->lockForUpdate()
            ->get();

        $totalAvailable = (int) $rows->sum('available');
        if (! config('inventory.allow_negative_stock', true) && $totalAvailable < $needed) {
            $sku = optional($item->product)->sku ?? $item->item_id;
            throw new \Exception("Stok tidak mencukupi di gudang asal untuk SKU {$sku} (butuh {$needed}, tersedia {$totalAvailable}).");
        }

        $remaining = $needed;
        $first = true;

        foreach ($rows as $row) {
            if ($remaining <= 0) {
                break;
            }

            $take = min($remaining, (int) $row->available);
            if ($take <= 0) {
                continue;
            }
            $remaining -= $take;

            if ($first) {
                $item->update([
                    'source_bin_id' => $row->bin_id,
                    'qty'           => $take,
                    'batch_no'      => $row->batch_no,
                    'serial_no'     => $row->serial_no,
                    'sync_status'   => InventoryTransferItem::SYNC_SYNCED,
                    'sync_error'    => null,
                ]);
                $first = false;
            } else {
                $this->transferRepository->createItem([
                    'inventory_transfer_id' => $transfer->id,
                    'item_id'               => $item->item_id,
                    'qty'                   => $take,
                    'source_bin_id'         => $row->bin_id,
                    'batch_no'              => $row->batch_no,
                    'serial_no'             => $row->serial_no,
                    'sync_status'           => InventoryTransferItem::SYNC_SYNCED,
                ]);
            }

            $row->on_order += $take;
            $this->inventoryRepository->updateStock($row);
        }
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

            [$transitLocationId, $transitBinId] = $this->resolveTransitLocation();

            foreach ($transfer->items as $item) {

                if ($item->sync_status !== InventoryTransferItem::SYNC_SYNCED) {
                    continue;
                }

                $sourceInventory = $this->inventoryRepository->findExactForUpdate(
                    $item->item_id,
                    $transfer->source_location_id,
                    $item->source_bin_id,
                    $item->batch_no,
                    $item->serial_no,
                );

                if ($sourceInventory) {
                    $releaseQty = min((int) $item->qty, (int) $sourceInventory->on_order);
                    $sourceInventory->on_order -= $releaseQty;
                    $this->inventoryRepository->updateStock($sourceInventory);

                    $this->movementRepository->create([
                        'item_id'            => $item->item_id,
                        'location_id'        => $transfer->source_location_id,
                        'bin_id'             => $item->source_bin_id,
                        'transaction_number' => $transfer->transfer_number,
                        'source'             => 'TRANSFER_OUT',
                        'qty'                => $releaseQty,
                        'balance'            => $sourceInventory->on_hand,
                        'transaction_date'   => now(),
                        'created_by'         => $data['cancelled_by'],
                    ]);
                }

                $transitInventory = $this->inventoryRepository->findExactForUpdate(
                    $item->item_id,
                    $transitLocationId,
                    $transitBinId,
                    $item->batch_no ?? '',
                    $item->serial_no ?? '',
                );

                if ($transitInventory) {
                    $deductQty = min((int) $item->qty, (int) $transitInventory->on_hand);
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
                        'created_by'         => $data['cancelled_by'],
                    ]);
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

    public function revertToDraft(string $transferId, array $data = []): InventoryTransfer
    {
        $transfer = DB::transaction(function () use ($transferId, $data) {
            $transfer = $this->transferRepository->findByIdForUpdate($transferId);

            if (! $transfer) {
                throw new \Exception('Transfer tidak ditemukan.');
            }

            if ($transfer->status === InventoryTransfer::STATUS_DRAFT) {
                return $this->transferRepository->findById($transferId);
            }

            if (! in_array($transfer->status, [
                InventoryTransfer::STATUS_APPROVED,
                InventoryTransfer::STATUS_IN_TRANSIT,
                InventoryTransfer::STATUS_RECEIVED,
            ])) {
                throw new \Exception("Transfer berstatus {$transfer->status} tidak bisa dikembalikan ke Baru Dibuat.");
            }

            $actor = $data['actor'] ?? $transfer->created_by ?? 'system';

            $this->cancelActiveTransferReceipts($transfer, $actor);
            $transfer = $this->transferRepository->findByIdForUpdate($transferId);

            if (! in_array($transfer->status, [InventoryTransfer::STATUS_APPROVED, InventoryTransfer::STATUS_IN_TRANSIT])) {
                throw new \Exception("Penerimaan transfer {$transfer->transfer_number} belum sepenuhnya dibatalkan (status {$transfer->status}). Coba ulangi atau cek menu Penerimaan Barang.");
            }

            if ($transfer->status === InventoryTransfer::STATUS_IN_TRANSIT) {
                foreach ($transfer->items as $item) {
                    if ($item->sync_status !== InventoryTransferItem::SYNC_SYNCED) {
                        continue;
                    }

                    $sourceInventory = $this->inventoryRepository->findExactForUpdate(
                        $item->item_id,
                        $transfer->source_location_id,
                        $item->source_bin_id,
                        $item->batch_no,
                        $item->serial_no,
                    );

                    if ($sourceInventory) {

                        $sourceInventory->on_hand += (int) $item->qty;
                        $sourceInventory->on_order += (int) $item->qty;
                        $this->inventoryRepository->updateStock($sourceInventory);

                        $this->movementRepository->create([
                            'item_id'            => $item->item_id,
                            'location_id'        => $transfer->source_location_id,
                            'bin_id'             => $item->source_bin_id,
                            'transaction_number' => $transfer->transfer_number,
                            'source'             => 'TRANSFER_OUT',
                            'qty'                => (int) $item->qty,
                            'balance'            => $sourceInventory->on_hand,
                            'transaction_date'   => now(),
                            'created_by'         => $actor,
                        ]);
                    }

                    [$transitLocationId, $transitBinId] = $this->resolveTransitLocation();

                    $transitInventory = $this->inventoryRepository->findExactForUpdate(
                        $item->item_id,
                        $transitLocationId,
                        $transitBinId,
                        $item->batch_no ?? '',
                        $item->serial_no ?? '',
                    );

                    if ($transitInventory) {
                        $deductQty = min((int) $item->qty, (int) $transitInventory->on_hand);
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
                            'created_by'         => $actor,
                        ]);
                    }
                }
            }

            $transfer->update([
                'status'      => InventoryTransfer::STATUS_DRAFT,
                'shipped_at'  => null,
                'printed_at'  => null,
                'printed_by'  => null,
                'approved_at' => null,
                'approved_by' => null,
            ]);

            \Modules\Inbound\Models\Inbound::where('source_type', 'transfer')
                ->where('source_id', $transfer->id)
                ->where('status', \Modules\Inbound\Models\Inbound::STATUS_DRAFT)
                ->delete();

            return $this->transferRepository->findById($transferId);
        });

        $this->notifyChannelStock($transfer->items->pluck('item_id')->unique()->all());

        return $transfer;
    }

    private function cancelActiveTransferReceipts(InventoryTransfer $transfer, string $actor): void
    {
        $inboundIds = \Modules\Inbound\Models\Inbound::where('source_type', 'transfer')
            ->where('source_id', $transfer->id)
            ->whereIn('status', [
                \Modules\Inbound\Models\Inbound::STATUS_PARTIAL,
                \Modules\Inbound\Models\Inbound::STATUS_RECEIVED,
                \Modules\Inbound\Models\Inbound::STATUS_PUTAWAY_IN_PROGRESS,
                \Modules\Inbound\Models\Inbound::STATUS_COMPLETED,
            ])
            ->pluck('id')
            ->all();

        if (empty($inboundIds)) {
            return;
        }

        $inboundService = app(InboundService::class);
        foreach ($inboundIds as $inboundId) {
            $inboundService->cancel($inboundId, $actor);
        }
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

            $missingBin = $transfer->items->first(fn ($it) => empty($it->source_bin_id));
            if ($missingBin) {
                throw new \Exception("Pilih rak asal untuk semua item sebelum transfer bisa dikirim.");
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

                $sourceInventory->on_order -= $item->qty;
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

                [$transitLocationId, $transitBinId] = $this->resolveTransitLocation();

                $transitInventory = $this->inventoryRepository->findOrCreateForUpdate(
                    $item->item_id,
                    $transitLocationId,
                    $transitBinId,
                    $item->batch_no ?? '',
                    $item->serial_no ?? '',
                );

                $transitInventory->on_hand += $item->qty;
                $this->inventoryRepository->updateStock($transitInventory);

                $this->movementRepository->create([
                    'item_id'            => $item->item_id,
                    'location_id'        => $transitLocationId,
                    'bin_id'             => $transitBinId,
                    'transaction_number' => $transfer->transfer_number,
                    'source'             => 'TRANSIT_IN',
                    'qty'                => $item->qty,
                    'balance'            => $transitInventory->on_hand,
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

        $this->createTransitInbound($transfer);

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
            $defaultDestBin = app(\Modules\Warehouse\Services\LocationBinService::class)
                ->getDefaultBin($transfer->destination_location_id);

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
                    $destBinId = $defaultDestBin?->id;

                    $destInventory = $this->inventoryRepository->findOrCreateForUpdate(
                        $item->item_id,
                        $transfer->destination_location_id,
                        $destBinId,
                        $item->batch_no,
                        $item->serial_no,
                    );

                    $destInventory->on_hand += $receivedQty;
                    $this->inventoryRepository->updateStock($destInventory);

                    $this->movementRepository->create([
                        'item_id'            => $item->item_id,
                        'location_id'        => $transfer->destination_location_id,
                        'bin_id'             => $destBinId,
                        'transaction_number' => $transfer->transfer_number,
                        'source'             => 'TRANSFER_IN',
                        'qty'                => $receivedQty,
                        'balance'            => $destInventory->on_hand,
                        'transaction_date'   => now(),
                        'created_by'         => $data['received_by'],
                    ]);
                }
            }

            $receiveNumber = $this->generateTrfiNumber();

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
                    'received_qty' => $item->received_qty,
                ])->values()->toArray();

            if (! empty($inboundItems)) {
                $inboundService->syncTransitReceived($transfer->id, [
                    'location_id'        => $transfer->destination_location_id,
                    'transaction_number' => $receiveNumber,
                    'reference_number'   => $transfer->transfer_number,
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
            ['is_inbound' => true]
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

    public function bulkDeleteOrRevertTransfers(array $ids, string $actor): array
    {
        $deleted = 0;
        $reverted = 0;
        $failed = [];

        foreach ($ids as $id) {
            try {
                $transfer = $this->getTransferById($id);
                if (! $transfer) {
                    throw new \Exception('Transfer tidak ditemukan.');
                }

                if ($transfer->status === InventoryTransfer::STATUS_IN_TRANSIT) {
                    $this->revertToDraft($id, ['actor' => $actor]);
                    $reverted++;
                } else {
                    $this->deleteTransfer($id, $actor);
                    $deleted++;
                }
            } catch (\Throwable $e) {
                $failed[] = [
                    'id' => $id,
                    'reason' => $e->getMessage(),
                ];
            }
        }

        return ['deleted' => $deleted, 'reverted' => $reverted, 'failed' => $failed];
    }

    public function summarizeBulkTransferResult(array $result): string
    {
        $parts = [];

        if (($result['deleted'] ?? 0) > 0) {
            $parts[] = "{$result['deleted']} dihapus";
        }
        if (($result['reverted'] ?? 0) > 0) {
            $parts[] = "{$result['reverted']} dikembalikan ke Baru Dibuat";
        }
        if (count($result['failed'] ?? []) > 0) {
            $parts[] = count($result['failed']) . ' gagal';
        }

        return $parts ? implode(', ', $parts) : 'Tidak ada transfer diproses';
    }

    public function getTransfersForBulkPdf(array $ids): array
    {
        $transfers = array_values(array_filter(array_map(
            fn (string $id) => $this->getTransferById($id),
            $ids,
        )));

        if (count($transfers) !== count($ids)) {
            $foundIds = array_map(fn ($t) => $t->id, $transfers);
            $missing = array_values(array_diff($ids, $foundIds));

            throw new \App\Exceptions\UserFacingException(
                'Data tidak ditemukan',
                'Sebagian dokumen tidak ditemukan: ' . implode(', ', $missing),
                404,
            );
        }

        return $transfers;
    }

    public function getIncomingTransfersPaginated(?string $statusFilter, ?string $locationId, int $limit = 10)
    {
        $filters = $statusFilter
            ? ['status' => $statusFilter]
            : ['statuses' => [InventoryTransfer::STATUS_IN_TRANSIT, InventoryTransfer::STATUS_RECEIVED]];

        if (! empty($locationId)) {
            $filters['destination_location_id'] = $locationId;
        }

        return $this->getTransfersPaginated($filters, $limit);
    }

    public function getOutgoingTransfersPaginated(?string $locationId, int $limit = 10)
    {
        $filters = ['status' => InventoryTransfer::STATUS_IN_TRANSIT];

        if (! empty($locationId)) {
            $filters['source_location_id'] = $locationId;
        }

        return $this->getTransfersPaginated($filters, $limit);
    }

    public function assertSkuHasStock(array $summary, ?string $locationId, bool $requireStock): void
    {
        if ($locationId && $requireStock && empty($summary['available_bins'])) {
            throw new \App\Exceptions\UserFacingException(
                'Stok tidak tersedia',
                'SKU tidak punya stok di gudang ini.',
                404,
            );
        }
    }

    public function deleteTransfer(string $id, ?string $actor = null): void
    {
        $transfer = $this->transferRepository->findById($id);

        if (! $transfer) {
            throw new \Exception('Transfer tidak ditemukan.');
        }

        $actor = $actor ?? $transfer->created_by ?? 'system';

        if (! in_array($transfer->status, [InventoryTransfer::STATUS_DRAFT, InventoryTransfer::STATUS_APPROVED])) {
            $this->revertToDraft($id, ['actor' => $actor]);
        }

        $itemIds = DB::transaction(function () use ($id) {
            $transfer = $this->transferRepository->findByIdForUpdate($id);

            if (!$transfer) {
                throw new \Exception('Transfer tidak ditemukan.');
            }

            if (!in_array($transfer->status, [InventoryTransfer::STATUS_DRAFT, InventoryTransfer::STATUS_APPROVED])) {
                throw new \Exception("Hanya transfer yang belum dikirim (Draft/Disetujui) yang bisa dihapus (status saat ini: {$transfer->status}).");
            }

            $itemIds = $transfer->items->pluck('item_id')->unique()->all();
            $actor = $transfer->created_by ?? 'system';

            [$transitLocationId, $transitBinId] = $this->resolveTransitLocation();

            foreach ($transfer->items as $item) {
                if ($item->sync_status !== InventoryTransferItem::SYNC_SYNCED) {
                    continue;
                }

                $sourceInventory = $this->inventoryRepository->findExactForUpdate(
                    $item->item_id,
                    $transfer->source_location_id,
                    $item->source_bin_id,
                    $item->batch_no,
                    $item->serial_no,
                );
                if ($sourceInventory) {
                    $releaseQty = min((int) $item->qty, (int) $sourceInventory->on_order);
                    $sourceInventory->on_order -= $releaseQty;
                    $this->inventoryRepository->updateStock($sourceInventory);

                    $this->movementRepository->create([
                        'item_id'            => $item->item_id,
                        'location_id'        => $transfer->source_location_id,
                        'bin_id'             => $item->source_bin_id,
                        'transaction_number' => $transfer->transfer_number,
                        'source'             => 'TRANSFER_OUT',
                        'qty'                => $releaseQty,
                        'balance'            => $sourceInventory->on_hand,
                        'transaction_date'   => now(),
                        'created_by'         => $actor,
                    ]);
                }

                $transitInventory = $this->inventoryRepository->findExactForUpdate(
                    $item->item_id,
                    $transitLocationId,
                    $transitBinId,
                    $item->batch_no ?? '',
                    $item->serial_no ?? '',
                );
                if ($transitInventory) {
                    $deductQty = min((int) $item->qty, (int) $transitInventory->on_hand);
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
                        'created_by'         => $actor,
                    ]);
                }
            }

            $this->transferRepository->delete($id);

            return $itemIds;
        });

        $this->notifyChannelStock($itemIds);
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

            if (! $sourceInventory) {
                $sourceInventory = $this->inventoryRepository->findOrCreateForUpdate(
                    $data['source_item_id'],
                    $data['location_id'],
                    $data['bin_id'] ?? null,
                );
            }

            if (! config('inventory.allow_negative_stock', true) && $sourceInventory->on_hand < $data['qty_to_split']) {
                throw new \Exception("Stok tidak mencukupi untuk split (tersedia: {$sourceInventory->on_hand}, diminta: {$data['qty_to_split']}).");
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

    public function generateTransferNumber(): string
    {
        do {
            $number = 'TRFO-' . str_pad((string) random_int(1, 999999999), 9, '0', STR_PAD_LEFT);
        } while (InventoryTransfer::where('transfer_number', $number)->exists());

        return $number;
    }

    public function createDraft(array $data): InventoryTransfer
    {
        $manualNumber = isset($data['transfer_number']) ? trim((string) $data['transfer_number']) : '';

        if ($manualNumber !== '' && InventoryTransfer::where('transfer_number', $manualNumber)->exists()) {
            throw new \Exception("Nomor transfer \"{$manualNumber}\" sudah digunakan.");
        }

        $transferNumber = $manualNumber !== '' ? $manualNumber : $this->generateTransferNumber();

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

    private function createTransitInbound(InventoryTransfer $transfer): void
    {
        $transfer->loadMissing('items');

        $items = $transfer->items
            ->map(fn ($item) => [
                'item_id'      => $item->item_id,
                'expected_qty' => $item->qty,
            ])->values()->toArray();

        if (empty($items)) {
            return;
        }

        app(InboundService::class)->createPendingTransit([
            'location_id'      => $transfer->destination_location_id,
            'reference_number' => $transfer->transfer_number,
            'source_id'        => $transfer->id,
            'expected_date'    => $transfer->shipped_at ?? $transfer->created_at ?? now(),
            'created_by'       => $transfer->created_by,
            'items'            => $items,
        ]);
    }

    public function submitDraft(string $transferId): InventoryTransfer
    {
        $transfer = DB::transaction(function () use ($transferId) {
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

            $missingBin = $transfer->items->first(fn ($it) => empty($it->source_bin_id));
            if ($missingBin) {
                throw new \Exception("Pilih rak asal untuk semua item sebelum transfer bisa dikirim.");
            }

            $notReady = $transfer->items->first(
                fn ($it) => $it->sync_status !== InventoryTransferItem::SYNC_SYNCED
            );
            if ($notReady) {
                $reason = $notReady->sync_status === InventoryTransferItem::SYNC_FAILED
                    ? ($notReady->sync_error ?? 'stok tidak mencukupi di rak asal')
                    : 'stok masih diproses, mohon tunggu beberapa detik lalu coba lagi';
                throw new \Exception("Transfer belum siap dikirim: {$reason}.");
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

                $sourceInventory->on_order -= $item->qty;
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

                [$transitLocationId, $transitBinId] = $this->resolveTransitLocation();

                $transitInventory = $this->inventoryRepository->findOrCreateForUpdate(
                    $item->item_id,
                    $transitLocationId,
                    $transitBinId,
                    $item->batch_no ?? '',
                    $item->serial_no ?? '',
                );

                $transitInventory->on_hand += $item->qty;
                $this->inventoryRepository->updateStock($transitInventory);

                $this->movementRepository->create([
                    'item_id'            => $item->item_id,
                    'location_id'        => $transitLocationId,
                    'bin_id'             => $transitBinId,
                    'transaction_number' => $transfer->transfer_number,
                    'source'             => 'TRANSIT_IN',
                    'qty'                => $item->qty,
                    'balance'            => $transitInventory->on_hand,
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

        $this->createTransitInbound($transfer);

        return $transfer;
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

        $deferReserve = ($data['defer_reserve'] ?? false) || empty($data['source_bin_id']);

        if (! $deferReserve) {

            SyncTransferDraftItemJob::dispatchSync(
                $item->id,
                SyncTransferDraftItemJob::ACTION_RESERVE,
                $data['qty'],
                $transfer->transfer_number,
                $transfer->created_by,
            );

            $item = $item->fresh();
            if ($item?->sync_status === InventoryTransferItem::SYNC_FAILED) {
                $error = $item->sync_error ?? 'Gagal mereservasi stok di rak asal.';
                $item->delete();
                throw new \Exception($error);
            }
        }

        return $item->load(['product:id,sku,product_id', 'sourceBin:id,bin_final_code', 'destinationBin:id,bin_final_code']);
    }

    public function updateDraftItemQty(
        string $transferId,
        string $itemId,
        int $newQty,
        ?string $newBinId = null,
        bool $binProvided = false,
    ): InventoryTransferItem {
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

        if ($binProvided && $newBinId !== $item->source_bin_id) {
            $this->removeDraftItem($transferId, $itemId);

            return $this->addDraftItem($transferId, [
                'item_id'       => $item->item_id,
                'qty'           => $newQty,
                'source_bin_id' => $newBinId,
                'batch_no'      => $item->batch_no,
                'serial_no'     => $item->serial_no,
            ]);
        }

        $oldQty = $item->qty;
        $delta = $newQty - $oldQty;
        $prevStatus = $item->sync_status;

        $item->update([
            'qty' => $newQty,
            'sync_status' => InventoryTransferItem::SYNC_PENDING,
        ]);

        if ($delta !== 0) {

            SyncTransferDraftItemJob::dispatchSync(
                $item->id,
                SyncTransferDraftItemJob::ACTION_ADJUST,
                $delta,
                $transfer->transfer_number,
                $transfer->created_by,
            );

            $fresh = $item->fresh();
            if ($fresh?->sync_status === InventoryTransferItem::SYNC_FAILED) {
                $error = $fresh->sync_error ?? 'Gagal menyesuaikan reservasi stok.';
                $fresh->update([
                    'qty' => $oldQty,
                    'sync_status' => $prevStatus,
                    'sync_error' => null,
                ]);
                throw new \Exception($error);
            }
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

        SyncTransferDraftItemJob::dispatchSync(
            $item->id,
            SyncTransferDraftItemJob::ACTION_RELEASE,
            $qty,
            $transfer->transfer_number,
            $transfer->created_by,
        );
    }
}
