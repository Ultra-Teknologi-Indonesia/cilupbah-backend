<?php

namespace Modules\Outbound\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShipmentOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $order = $this->order;
        $packlist = $this->packlist;

        return [
            'id' => $this->id,
            'shipment_id' => $this->shipment_id,
            'order_id' => $this->order_id,
            'packlist_id' => $this->packlist_id,
            'tracking_number' => $this->tracking_number,
            'qty_given' => $this->qty_given,
            'pickup_status' => $this->pickup_status,
            'pickup_message' => $this->pickup_message,
            'scanned_at' => $this->created_at?->toISOString(),
            'order' => $order ? [
                'id' => $order->id,
                'salesorder_no' => $order->salesorder_no,
                'customer_name' => $order->customer_name,
                'status' => $order->status,
                'grand_total' => $order->grand_total,
                'shipping_provider' => $order->shipping_provider,
                'tracking_number' => $order->tracking_number,
                'source' => $order->source,
                'channel_order_no' => $order->channel_order_no,
                'order_weight_gram' => $order->order_weight_gram,
                'channel_status' => $order->channel_status,
            ] : null,
            'packlist' => $packlist ? [
                'id' => $packlist->id,
                'packlist_no' => $packlist->packlist_no,
            ] : null,
        ];
    }
}
