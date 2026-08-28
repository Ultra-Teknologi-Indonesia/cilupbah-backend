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
                $sourceLabel = 'Transfer';
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
        }

        return [
            'id' => $this->id,
            'item_id' => $this->item_id,
            'sku' => $this->whenLoaded('product', fn () => $this->product?->sku),
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
            'transaction_date' => $this->transaction_date,
            'created_by' => ActorName::resolve($this->created_by),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
