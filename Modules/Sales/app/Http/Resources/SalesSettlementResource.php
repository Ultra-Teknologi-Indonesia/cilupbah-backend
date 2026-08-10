<?php

namespace Modules\Sales\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SalesSettlementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return $this->resource->attributesToArray();
    }
}
