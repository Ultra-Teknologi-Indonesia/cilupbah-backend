<?php

namespace Modules\Product\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Product\Support\TechnicalSku;

class ProductPriceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $visibleVariants = $this->is_bundle
            ? collect()
            : $this->variants->filter(fn ($variant) => ! TechnicalSku::isTechnical($variant->sku));
        $prices = $visibleVariants->pluck('sell_price')->filter(fn ($p) => $p !== null);

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
