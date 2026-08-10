<?php

namespace Modules\Sales\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SalesReturnSettlementInvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return array_merge($this->resource->attributesToArray(), [
            'settlement' => $this->whenLoaded('settlement'),
            'invoice'    => $this->whenLoaded('invoice'),
        ]);
    }
}
