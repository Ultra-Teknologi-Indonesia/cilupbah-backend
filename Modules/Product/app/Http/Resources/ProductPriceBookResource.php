<?php

namespace Modules\Product\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductPriceBookResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'variant_id' => $this->variant_id,
            'sku' => $this->whenLoaded('variant', fn () => $this->variant?->sku),
            'customer_type' => $this->customer_type,
            'min_qty' => $this->min_qty,
            'max_qty' => $this->max_qty,
            'price' => $this->price,
        ];
    }
}
