<?php

namespace Modules\Product\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VariantSyncResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $variant = $this->resource->relationLoaded('variant') ? $this->variant : null;

        return [
            'variant_id'      => $this->variant_id,
            'sku'             => $variant->sku ?? null,
            'external_sku_id' => $this->external_sku_id,
            'sell_price'      => $variant?->sell_price !== null ? (float) $variant->sell_price : null,
            'synced_price'    => $this->synced_price !== null ? (float) $this->synced_price : null,
            'synced_stock'    => $this->synced_stock,
        ];
    }
}
