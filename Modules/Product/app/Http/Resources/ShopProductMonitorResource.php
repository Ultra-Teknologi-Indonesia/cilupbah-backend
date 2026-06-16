<?php

namespace Modules\Product\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShopProductMonitorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $product = $this->resource->relationLoaded('product') ? $this->product : null;

        $primaryImage = null;
        if ($product && $product->relationLoaded('media')) {
            $primaryImage = $product->media->firstWhere('is_primary', true)?->url
                ?? $product->media->first()?->url;
        }

        return [
            'product_id'          => $this->product_id,
            'product_name'        => $product->name ?? null,
            'sku'                 => $product->sku ?? null,
            'product_status'      => $product->status ?? null,
            'primary_image'       => $primaryImage,
            'external_product_id' => $this->external_product_id,
            'sync_status'         => $this->sync_status,
            'error_message'       => $this->error_message,
            'last_synced_at'      => $this->last_synced_at,
            'skus'                => $this->resource->relationLoaded('variantMappings')
                ? VariantSyncResource::collection($this->variantMappings)->resolve()
                : [],
        ];
    }
}
