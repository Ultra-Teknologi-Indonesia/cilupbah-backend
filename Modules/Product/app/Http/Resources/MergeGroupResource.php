<?php

namespace Modules\Product\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property array $resource
 */
class MergeGroupResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $g = $this->resource;

        return [
            'name' => $g['name'],
            'norm_key' => $g['norm_key'],
            'merged' => $g['merged'],
            'hidden' => $g['hidden'],
            'foto' => $g['foto'],
            'vendor' => $g['vendor'],
            'category' => $g['category'],
            'product_count' => $g['product_count'],
            'sku_count' => $g['sku_count'],
            'products' => $g['products'],
            'skus' => $g['skus'],
            'channels' => $g['channels'],
            'channel_count' => $g['channel_count'],
        ];
    }
}
