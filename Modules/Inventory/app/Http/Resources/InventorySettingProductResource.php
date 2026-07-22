<?php

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventorySettingProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'item_id'            => $this->id,
            'sku'               => $this->sku,
            'product_name'      => $this->product_name ?? $this->whenLoaded('product', fn () => $this->product?->name),
            'variation_values'  => $this->variationValues(),
            'thumbnail'         => $this->resolveThumbnail(),
            'barcode'           => $this->barcode,
            'min_stock'         => (int) ($this->min_stock ?? 0),
            'safe_stock'        => (int) ($this->safe_stock ?? 0),
            'purchase_lead_time' => (int) ($this->purchase_lead_time ?? 0),
            'is_unlimited_stock' => (int) ($this->unlimited_shops_count ?? 0) > 0,
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
