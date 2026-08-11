<?php

namespace Modules\Warehouse\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \Modules\Warehouse\Models\LocationBin
 */
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
        $skus = collect();

        if ($this->relationLoaded('activeInventories') && $this->activeInventories) {
            $this->activeInventories->each(function ($inv) use ($skus) {
                $skus->push([
                    'item_id' => $inv->item_id,
                    'sku' => $inv->product?->sku,
                    'name' => $inv->product?->product?->name,
                    'on_hand' => (int) $inv->on_hand,
                    'on_order' => (int) $inv->on_order,
                ]);
            });
        }

        if ($this->relationLoaded('skuRackAssignments') && $this->skuRackAssignments) {
            $this->skuRackAssignments->each(function ($assignment) use ($skus) {
                if (! $skus->contains('item_id', $assignment->item_id)) {
                    $skus->push([
                        'item_id' => $assignment->item_id,
                        'sku' => $assignment->item?->sku,
                        'name' => $assignment->item?->product?->name,
                        'on_hand' => 0,
                        'on_order' => 0,
                    ]);
                }
            });
        }

        return $skus->groupBy('item_id')->map(function ($group) {
            $first = $group->first();
            return [
                'variant_id' => $first['item_id'],
                'sku' => $first['sku'],
                'name' => $first['name'],
                'on_hand' => $group->sum('on_hand'),
                'on_order' => $group->sum('on_order'),
            ];
        })->values()->all();
    }
}
