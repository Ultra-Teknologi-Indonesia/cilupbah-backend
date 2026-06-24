<?php

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $inventories = $this->relationLoaded('inventories') ? $this->inventories : collect();

        return [
            'item_id' => $this->id,
            'item_code' => $this->sku,
            'item_name' => $this->whenLoaded('product', fn () => $this->product?->name),
            'item_group_id' => $this->product_id,
            'is_bundle' => $this->whenLoaded('product', fn () => (bool) $this->product?->is_bundle, false),
            'variation_values' => $this->variationValues(),
            'brand_name' => $this->whenLoaded('product', fn () => $this->product?->brand?->name),
            'stock_this' => $this->whenLoaded('product', fn () => (bool) $this->product?->is_stored, true),
            'average_cost' => $this->weightedAverageCost($inventories),
            'location_stocks' => $this->locationStocks($inventories),
            'total_stocks' => $this->totalStocks($inventories),
            'thumbnail' => $this->resolveThumbnail(),
        ];
    }

    protected function variationValues(): array
    {
        if (! $this->relationLoaded('options')) {
            return [];
        }

        return $this->options->map(fn ($opt) => [
            'label' => $opt->relationLoaded('attribute') ? $opt->attribute?->name : null,
            'value' => $opt->value,
        ])->values()->toArray();
    }

    protected function weightedAverageCost($inventories): string
    {
        if ($inventories->isEmpty()) {
            return '0.0000';
        }

        $totalQty = $inventories->sum('on_hand');
        if ($totalQty <= 0) {
            return number_format($inventories->avg('avg_cost') ?? 0, 4, '.', '');
        }

        $weightedSum = $inventories->sum(fn ($inv) => $inv->on_hand * $inv->avg_cost);

        return number_format($weightedSum / $totalQty, 4, '.', '');
    }

    protected function locationStocks($inventories): array
    {
        if ($inventories->isEmpty()) {
            return [];
        }

        return $inventories->map(fn ($inv) => [
            'item_id' => $this->id,
            'location_id' => $inv->location_id,
            'location_name' => $inv->relationLoaded('location') ? $inv->location?->location_name : null,
            'on_hand' => (int) $inv->on_hand,
            'on_order' => (int) $inv->on_order,
            'reserved' => (int) $inv->reserved,
            'available' => (int) $inv->available,
        ])->values()->toArray();
    }

    protected function totalStocks($inventories): array
    {
        return [
            'on_hand' => (int) $inventories->sum('on_hand'),
            'on_order' => (int) $inventories->sum('on_order'),
            'reserved' => (int) $inventories->sum('reserved'),
            'available' => (int) $inventories->sum('available'),
        ];
    }

    protected function resolveThumbnail(): ?string
    {
        if ($this->relationLoaded('media') && $this->media->isNotEmpty()) {
            $primary = $this->media->firstWhere('is_primary', true);

            return $primary ? $primary->url : $this->media->first()->url;
        }

        if ($this->relationLoaded('product') && $this->product?->relationLoaded('media') && $this->product->media->isNotEmpty()) {
            $primary = $this->product->media->firstWhere('is_primary', true);

            return $primary ? $primary->url : $this->product->media->first()->url;
        }

        return null;
    }
}
