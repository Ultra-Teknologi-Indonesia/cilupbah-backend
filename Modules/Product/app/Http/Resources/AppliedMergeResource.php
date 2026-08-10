<?php

namespace Modules\Product\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property array $resource
 */
class AppliedMergeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $m = $this->resource;

        return [
            'master_name' => $m['master_name'],
            'product_count' => $m['product_count'],
            'products' => $m['products'],
            'channels' => $m['channels'],
            'channel_count' => $m['channel_count'],
        ];
    }
}
