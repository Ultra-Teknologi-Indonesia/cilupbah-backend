<?php

namespace Modules\Sales\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SalesReturnSettlementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return array_merge($this->resource->attributesToArray(), [
            'sales_return' => $this->whenLoaded('salesReturn'),
            'invoices'     => $this->whenLoaded('invoices'),
            'refunds'      => $this->whenLoaded('refunds'),
        ]);
    }
}
