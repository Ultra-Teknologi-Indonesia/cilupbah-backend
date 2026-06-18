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
            'mismatches' => $this->mismatches(),
        ];
    }

    /**
     * Rincian status Tidak Cocok (atribut/harga/sku) per channel, dari
     * tabel materialized product_channel_validations.
     */
    protected function mismatches(): array
    {
        if (! $this->relationLoaded('channelValidations')) {
            return [];
        }

        return $this->channelValidations->map(fn ($v) => [
            'channel_code' => $v->relationLoaded('channel') ? ($v->channel->code ?? null) : null,
            'attribute_status' => $v->attribute_status,
            'attribute_issues' => $v->attribute_issues ?? [],
            'price_status' => $v->price_status,
            'price_issues' => $v->price_issues ?? [],
            'sku_status' => $v->sku_status,
            'sku_issues' => $v->sku_issues ?? [],
        ])->values()->all();
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
