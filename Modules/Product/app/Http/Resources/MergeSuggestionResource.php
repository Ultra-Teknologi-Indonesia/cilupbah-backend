<?php

namespace Modules\Product\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property array $resource
 */
class MergeSuggestionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $s = $this->resource;

        return [
            'prefix' => $s['prefix'],
            'suggested_master_name' => $s['suggested_master_name'],
            'existing_master' => $s['existing_master'],
            'unique_name_count' => $s['unique_name_count'],
            'total' => $s['total'],
            'products' => $s['products'],
        ];
    }
}
