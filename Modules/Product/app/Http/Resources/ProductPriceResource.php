<?php

namespace Modules\Product\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductPriceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $prices = $this->variants->pluck('sell_price')->filter(fn ($p) => $p !== null);
        $visibleVariants = $this->is_bundle ? collect() : $this->variants;

        return [
            'item_id' => $this->id,
            'sku' => $this->sku,
            'name' => $this->name,
            'min_price' => $prices->isNotEmpty() ? (float) $prices->min() : 0,
            'max_price' => $prices->isNotEmpty() ? (float) $prices->max() : 0,
            'variants' => $visibleVariants->map(fn ($v) => [
                'id' => $v->id,
                'sku' => $v->sku,
                'sell_price' => $v->sell_price,
            ])->values(),
        ];
    }
}
