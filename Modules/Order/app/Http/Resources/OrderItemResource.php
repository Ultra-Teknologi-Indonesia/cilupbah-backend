<?php

namespace Modules\Order\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderItemResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'item_id' => $this->item_id,
            'channel_product_id' => $this->channel_product_id,
            'sku' => $this->sku,
            'description' => $this->description,
            'qty_in_base' => $this->qty_in_base,
            'price' => $this->price,
            'disc' => $this->disc,
            'disc_amount' => $this->disc_amount,
            'tax_amount' => $this->tax_amount,
            'amount' => $this->amount,
        ];
    }
}
