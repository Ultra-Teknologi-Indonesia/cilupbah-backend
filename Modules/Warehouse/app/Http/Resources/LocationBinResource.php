<?php

namespace Modules\Warehouse\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LocationBinResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'location_id' => $this->location_id,
            'zone_id' => $this->zone_id,
            'floor_code' => $this->floor_code,
            'row_code' => $this->row_code,
            'column_code' => $this->column_code,
            'bin_code' => $this->bin_code,
            'bin_final_code' => $this->bin_final_code,
            'is_inbound' => (bool) $this->is_inbound,
            'is_stock_acknowledged' => (bool) $this->is_stock_acknowledged,
            'is_large_bin' => (bool) $this->is_large_bin,
            'allows_multi_sku' => app(\Modules\Warehouse\Services\BinMultiSkuRuleService::class)
                ->allowsMultiSkuCode((string) $this->location_id, $this->bin_final_code),
            'category' => $this->category,
            'skus' => $this->buildSkuSummary(),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    protected function buildSkuSummary(): array
    {
        $map = [];

        if ($this->relationLoaded('activeInventories') && $this->activeInventories) {
            foreach ($this->activeInventories as $inv) {
                $product = $inv->product;
                if (! $product || empty($product->sku)) {
                    continue;
                }

                $itemId = (string) $inv->item_id;
                if (! isset($map[$itemId])) {
                    $map[$itemId] = [
                        'variant_id' => $itemId,
                        'sku'        => $product->sku,
                        'name'       => $product->product?->name,
                        'on_hand'    => 0,
                        'on_order'   => 0,
                    ];
                }

                $map[$itemId]['on_hand'] += (int) $inv->on_hand;
                $map[$itemId]['on_order'] += (int) $inv->on_order;
            }
        }

        if ($this->relationLoaded('skuRackAssignments') && $this->skuRackAssignments) {
            foreach ($this->skuRackAssignments as $assignment) {
                $item = $assignment->item;
                if (! $item || empty($item->sku)) {
                    continue;
                }

                $itemId = (string) $assignment->item_id;
                if (! isset($map[$itemId])) {
                    $map[$itemId] = [
                        'variant_id' => $itemId,
                        'sku'        => $item->sku,
                        'name'       => $item->product?->name,
                        'on_hand'    => 0,
                        'on_order'   => 0,
                    ];
                }
            }
        }

        return array_values($map);
    }
}
