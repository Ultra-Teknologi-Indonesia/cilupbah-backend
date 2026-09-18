<?php

namespace Modules\Outbound\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class PacklistItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $product = $this->relationLoaded('product') ? $this->product : null;
        $orderItem = $this->relationLoaded('orderItem') ? $this->orderItem : null;

        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'item_id' => $this->item_id,
            'qty_ordered' => (int) $this->qty_ordered,
            'qty_packed' => (int) $this->qty_packed,
            'barcode_verified' => (bool) $this->barcode_verified,
            'product' => $product ? [
                'sku' => $product->sku,
                'product_id' => $product->product_id,
                'media' => $product->relationLoaded('media')
                    ? $product->media->map(fn ($media) => [
                        'url' => $media->url,
                        'is_primary' => (bool) $media->is_primary,
                        'sort_order' => $media->sort_order,
                    ])->values()
                    : [],
                'product' => $product->relationLoaded('product') && $product->product ? [
                    'name' => $product->product->name,
                    'media' => $product->product->relationLoaded('media')
                        ? $product->product->media->map(fn ($media) => [
                            'url' => $media->url,
                            'is_primary' => (bool) $media->is_primary,
                            'sort_order' => $media->sort_order,
                        ])->values()
                        : [],
                ] : null,
            ] : null,
            'order_item' => $orderItem ? [
                'sku' => $orderItem->sku,
                'description' => $orderItem->description,
                'image_url' => null,
            ] : null,
        ];
    }
}
