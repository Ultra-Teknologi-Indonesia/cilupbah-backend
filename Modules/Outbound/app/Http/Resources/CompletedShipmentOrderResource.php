<?php

namespace Modules\Outbound\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CompletedShipmentOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $shipmentOrder = $this->resource;
        $order = $shipmentOrder->order;
        $shipment = $shipmentOrder->shipment;
        $packlist = $shipmentOrder->packlist;

        return [
            'order_id' => $order?->id,
            'salesorder_no' => $order?->salesorder_no,
            'customer_name' => $order?->customer_name,
            'tracking_number' => $shipmentOrder->tracking_number ?? $order?->tracking_number,
            'source' => $order?->source,
            'channel_status' => $order?->channel_status,
            'channel_order_no' => $order?->channel_order_no,
            'transaction_date' => $order?->transaction_date,
            'shipping_provider' => $order?->shipping_provider,
            'courier_name' => $shipment?->courier_name ?? $order?->courier_name,
            'shipping_address' => $order?->shipping_address,
            'shipping_city' => $order?->shipping_city,
            'shipping_province' => $order?->shipping_province,
            'shipment_id' => $shipment?->id,
            'shipment_no' => $shipment?->shipment_no,
            'shipment_type' => $shipment?->shipment_type,
            'shipment_status' => $shipment?->status,
            'shipment_date' => $shipment?->shipment_date,
            'handed_over_at' => $shipment?->handed_over_at,
            'location_name' => $shipment?->location?->location_name,
            'picklist_no' => $packlist?->packlist_no,
            'qty_given' => $shipmentOrder->qty_given,
            'pickup_code' => $order?->pickup_code,
        ];
    }
}
