<?php

namespace Modules\Outbound\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class PacklistDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $order = $this->relationLoaded('order') ? $this->order : null;
        $location = $this->relationLoaded('location') ? $this->location : null;
        $packer = $this->relationLoaded('packer') ? $this->packer : null;

        return [
            'id' => $this->id,
            'packlist_no' => $this->packlist_no,
            'location_id' => $this->location_id,
            'location' => $location ? [
                'id' => $location->id,
                'location_name' => $location->location_name,
                'location_code' => $location->location_code,
            ] : null,
            'packer_id' => $this->packer_id,
            'packer' => $packer ? [
                'id' => $packer->id,
                'name' => $packer->name,
                'email' => $packer->email,
            ] : null,
            'order_id' => $this->order_id,
            'order' => $order ? [
                'id' => $order->id,
                'salesorder_no' => $order->salesorder_no,
                'customer_name' => $order->customer_name,
                'transaction_date' => $order->transaction_date,
                'is_instant' => (bool) $order->is_instant,
                'shipping_provider' => $order->shipping_provider,
                'shipping_type' => $order->shipping_type,
                'source' => $order->source,
                'tracking_number' => $order->tracking_number,
            ] : null,
            'status' => $this->status,
            'package_count' => (int) ($this->package_count ?? 1),
            'items' => $this->whenLoaded('items', fn () => PacklistItemResource::collection($this->items)),
        ];
    }
}
