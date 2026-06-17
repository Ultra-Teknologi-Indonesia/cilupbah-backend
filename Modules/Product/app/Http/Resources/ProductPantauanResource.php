<?php

namespace Modules\Product\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductPantauanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'product_id' => $this->id,
            'product_name' => $this->name,
            'sku' => $this->sku,
            'primary_image' => $this->primaryImage(),
            'category_name' => $this->relationLoaded('category') ? ($this->category->name ?? null) : null,
            'product_type' => $this->is_bundle ? 'bundle' : ($this->is_consignment ? 'konsinyasi' : 'satuan'),
            'not_uploaded_count' => $this->not_uploaded_count !== null ? (int) $this->not_uploaded_count : null,
            'last_upload_error' => $this->last_upload_error ?? null,
        ];
    }

    protected function primaryImage(): ?string
    {
        if (! $this->relationLoaded('media')) {
            return null;
        }

        $media = $this->media;
        $primary = $media->whereNull('variant_id')->firstWhere('is_primary', true)
            ?? $media->whereNull('variant_id')->first()
            ?? $media->first();

        return $primary->url ?? null;
    }
}
