<?php

namespace Modules\Product\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Baris varian untuk tab "Variasi" detail produk (ringkas + berpaginasi).
 * stock = jumlah available dari inventories (withSum di controller).
 */
class ProductVariantRowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'barcode' => $this->barcode,
            'sell_price' => $this->sell_price,
            'is_active' => (bool) $this->is_active,
            'options' => $this->whenLoaded('options', fn () => $this->options
                ->map(fn ($o) => [
                    'attribute_id' => $o->attribute_id,
                    'value' => $o->value,
                ])->values()),
            'stock' => (int) ($this->inventories_sum_available ?? 0),
        ];
    }
}
