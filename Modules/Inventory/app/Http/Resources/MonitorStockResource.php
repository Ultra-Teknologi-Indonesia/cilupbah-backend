<?php

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MonitorStockResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $onHand = (int) ($this->total_on_hand ?? 0);
        $onOrder = (int) ($this->total_on_order ?? 0);
        $available = (int) ($this->total_available ?? 0);
        $minStock = (int) ($this->min_stock ?? 0);
        $safeStock = (int) ($this->safe_stock ?? 0);

        $target = $safeStock > 0 ? $safeStock : $minStock;
        $qtyToRestock = max(0, $target - $available);

        return [
            'item_id' => $this->id,
            'sku' => $this->sku,
            'product_name' => $this->product_name ?? $this->whenLoaded('product', fn () => $this->product?->name),
            'pending_order_nos' => $this->pending_order_nos,
            'variation_values' => $this->variationValues(),
            'thumbnail' => $this->resolveThumbnail(),
            'min_stock' => $minStock,
            'safe_stock' => $safeStock,
            'on_hand' => $onHand,
            'on_order' => $onOrder,
            'available' => $available,
            'qty_to_restock' => $qtyToRestock,
            'has_active_restock_request' => (bool) ($this->has_active_restock_request ?? false),
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
