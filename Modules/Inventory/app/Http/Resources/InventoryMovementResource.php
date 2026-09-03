<?php

namespace Modules\Inventory\Http\Resources;

use App\Support\ActorName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Inventory\Support\InventoryMovementSourceMap;

class InventoryMovementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $meta = InventoryMovementSourceMap::meta($this->source);
        $rawQty = (int) $this->qty;

        if (in_array($this->source, InventoryMovementSourceMap::ORDER_DEDUCT_SOURCES, true)) {
            $qty = -abs($rawQty);
            $direction = 'out';
        } elseif (in_array($this->source, InventoryMovementSourceMap::ORDER_RESTORE_SOURCES, true)) {
            $qty = abs($rawQty);
            $direction = 'in';
        } else {
            $qty = $rawQty;
            $direction = $qty > 0 ? 'in' : ($qty < 0 ? 'out' : 'none');
        }

        $sourceCategory = $meta['category'];
        $sourceLabel = $meta['label'];
        $workflow = match ($this->source) {
            'TRANSFER_OUT' => ['type' => 'transfer', 'label' => 'Keluar dari rak asal', 'order' => 10],
            'TRANSIT_IN' => ['type' => 'transfer', 'label' => 'Masuk transit', 'order' => 20],
            'TRANSIT_OUT' => ['type' => 'transfer', 'label' => 'Keluar transit', 'order' => 30],
            'TRANSFER_IN' => ['type' => 'transfer', 'label' => 'Masuk gudang tujuan', 'order' => 40],
            default => null,
        };

        if ($workflow !== null) {
            $sourceCategory = 'TRANSFER';
            $sourceLabel = $workflow['label'];
        }

        if (in_array($this->source, ['PUTAWAY_IN', 'PUTAWAY_OUT', 'PUTAWAY_REVERSAL'], true)) {
            $putawayType = strtolower((string) ($this->putaway_source_type ?? ''));
            $refNo = strtoupper((string) ($this->ref_no ?? ''));
            if (
                $putawayType === 'transfer' ||
                $putawayType === 'transit_in' ||
                str_starts_with($refNo, 'TRFI') ||
                str_starts_with($refNo, 'TRF') ||
                str_contains($putawayType, 'transfer')
            ) {
                $sourceCategory = 'TRANSFER';
                $workflow = match ($this->source) {
                    'PUTAWAY_OUT' => ['type' => 'transfer', 'label' => 'Keluar dari DEFAULT', 'order' => 50],
                    'PUTAWAY_IN' => ['type' => 'transfer', 'label' => 'Masuk rak alokasi', 'order' => 60],
                    default => ['type' => 'transfer', 'label' => 'Koreksi putaway transfer', 'order' => 70],
                };
                $sourceLabel = $workflow['label'];
            } elseif (
                $putawayType === 'sales_return' ||
                $putawayType === 'return' ||
                str_starts_with($refNo, 'RET') ||
                str_contains($putawayType, 'return')
            ) {
                $sourceCategory = 'RETUR_PENJUALAN';
                $sourceLabel = 'Retur Penjualan';
            }
        } elseif ($this->source === 'PICKING') {
            if (! empty($this->has_invoice)) {
                $sourceCategory = 'FAKTUR';
                $sourceLabel = 'Faktur';
            }
        } elseif (in_array($this->source, ['PACKING', 'PACKING_REVERSAL'], true)) {
            $sourceCategory = 'FAKTUR';
            $sourceLabel = $this->source === 'PACKING' ? 'Barang dipacking' : 'Koreksi Packing';
        }

        if (
            in_array($this->source, InventoryMovementSourceMap::CLEAN_HISTORICAL_PICKING_SOURCES, true)
            && $this->created_by === 'system:backfill'
        ) {
            $sourceCategory = 'FAKTUR';
            $sourceLabel = $this->source === 'ORDER_COMPLETE_REVERSAL'
                ? 'Pembalikan picking historis'
                : 'Picking historis';
        }

        $stockEffect = $this->stockEffect($this->source);

        return [
            'id' => $this->id,
            'item_id' => $this->item_id,
            'sku' => $this->whenLoaded('product', function () {
                $variant = $this->product;
                $parent = $variant?->relationLoaded('product') ? $variant->product : null;

                return $parent?->is_bundle && $parent->sku
                    ? $parent->sku
                    : $variant?->sku;
            }),
            'product_id' => $this->whenLoaded('product', fn () => $this->product?->product_id),
            'location_id' => $this->location_id,
            'location_name' => $this->whenLoaded('location', fn () => $this->location?->location_name),
            'bin_id' => $this->bin_id,
            'bin_code' => $this->whenLoaded('bin', fn () => $this->bin?->bin_final_code),
            'transaction_number' => $this->transaction_number,
            'order_no' => $this->pick_order_no,
            'order_count' => $this->pick_order_count !== null ? (int) $this->pick_order_count : null,
            'reference_number' => $this->ref_no,
            'note' => $this->ref_note,
            'source' => $this->source,
            'source_category' => $sourceCategory,
            'source_label' => $sourceLabel,
            'stock_effect' => $stockEffect,
            'workflow' => $workflow,
            'is_variance' => InventoryMovementSourceMap::isVariance($this->source),
            'direction' => $direction,
            'qty' => $qty,

            'balance' => (int) ($this->placed_balance ?? $this->physical_balance ?? $this->balance),
            'placed_balance' => (int) ($this->placed_balance ?? $this->physical_balance ?? $this->balance),
            'pending_placement_balance' => (int) ($this->pending_placement_balance ?? 0),
            'legacy_unassigned_balance' => (int) ($this->legacy_unassigned_balance ?? 0),
            'physical_total_balance' => (int) ($this->physical_total_balance ?? $this->balance),
            'on_order_balance' => (int) ($this->on_order_balance ?? 0),
            'available_balance' => (int) ($this->total_balance ?? $this->balance),
            'current_balance' => (int) ($this->current_balance ?? 0),
            'current_available_balance' => (int) ($this->current_available_balance ?? 0),
            'transaction_date' => $this->transaction_date,
            'created_by' => ActorName::resolve($this->created_by),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    private function stockEffect(string $source): ?array
    {
        if ($source === 'ORDER_RELEASE') {
            return [
                'type' => 'reservation_release',
                'label' => 'Cadangan dilepas',
                'quantity_label' => 'tersedia',
                'description' => 'Cadangan pesanan dilepas. Stok fisik tidak berubah.',
            ];
        }

        if (in_array($source, [
            'ORDER_RESTORE',
            'ORDER_RESTORE_CANCEL',
            'ORDER_COMPLETE_REVERSAL',
            'PICKING_REVERSAL',
            'PACKING_REVERSAL',
        ], true)) {
            return [
                'type' => 'physical_restore',
                'label' => 'Stok fisik dikembalikan',
                'quantity_label' => 'fisik',
                'description' => 'Barang dikembalikan ke stok fisik.',
            ];
        }

        if ($source === 'PACKING') {
            return [
                'type' => 'physical_deduct',
                'label' => 'Stok fisik dipotong',
                'quantity_label' => 'fisik',
                'description' => 'Stok fisik dipotong saat packing selesai.',
            ];
        }

        return null;
    }
}
