<?php

namespace Modules\Sales\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderBuyerConfirmationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'order_item_id' => $this->order_item_id,
            'salesorder_no' => $this->whenLoaded('order', fn () => $this->order->salesorder_no),
            'customer_name' => $this->whenLoaded('order', fn () => $this->order->customer_name),
            'order_date' => $this->whenLoaded('order', fn () => $this->order->order_date),
            'source' => $this->whenLoaded('order', fn () => $this->order->source),
            'item_id' => $this->item_id,
            'sku' => $this->whenLoaded('product', fn () => $this->product?->sku),
            'qty_short' => (int) $this->qty_short,
            'outcome' => $this->outcome,
            'replacement_item_id' => $this->replacement_item_id,
            'replacement_sku' => $this->whenLoaded('replacement', fn () => $this->replacement?->sku),
            'note' => $this->note,
            'raised_at' => $this->raised_at,
            'confirmed_at' => $this->confirmed_at,
            'resolved_at' => $this->resolved_at,
        ];
    }
}
