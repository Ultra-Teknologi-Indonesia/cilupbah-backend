<?php

namespace Modules\Product\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PriceListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'variant_id' => $this->id,
            'sku' => $this->sku,
            'sell_price' => $this->sell_price,
            'tax_rate' => $this->tax_rate,
            'product' => $this->whenLoaded('product', fn () => $this->product ? [
                'id' => $this->product->id,
                'name' => $this->product->name,
                'sku' => $this->product->sku,
            ] : null),
            'wholesale' => $this->whenLoaded('wholesalePrices', fn () => $this->wholesalePrices->map(fn ($w) => [
                'customer_type' => $w->customer_type,
                'min_qty' => $w->min_qty,
                'max_qty' => $w->max_qty,
                'price' => $w->price,
            ])->values()),
        ];
    }
}
