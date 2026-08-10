<?php

namespace Modules\Sales\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SalesInvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return array_merge($this->resource->attributesToArray(), [
            'order'    => $this->whenLoaded('order'),
            'location' => $this->whenLoaded('location'),
            'items'    => $this->whenLoaded('items'),
            'payments' => $this->whenLoaded('payments'),
        ]);
    }
}
