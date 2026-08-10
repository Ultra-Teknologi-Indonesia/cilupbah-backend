<?php

namespace Modules\Sales\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SalesPaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = array_merge($this->resource->attributesToArray(), [
            'invoice' => $this->whenLoaded('invoice'),
        ]);

        if ($this->resource->relationLoaded('paymentMethod')) {
            $data['payment_method'] = $this->resource->getRelation('paymentMethod');
        }

        return $data;
    }
}
