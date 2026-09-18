<?php

namespace Modules\Outbound\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FulfillmentOrderListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'salesorder_no' => $this->salesorder_no,
            'channel_order_no' => $this->channel_order_no,
            'channel_buyer_id' => $this->channel_buyer_id,
            'customer_name' => $this->customer_name,
            'shipping_full_name' => $this->shipping_full_name,
            'source' => $this->source,
            'commerce_platform' => $this->commerce_platform,
            'channel_shop_id' => $this->channel_shop_id,
            'is_manual' => (bool) $this->is_manual,
            'shipping_label_supported' => (bool) $this->shipping_label_supported,
            'status' => $this->status,
            'is_paid' => (bool) $this->is_paid,
            'grand_total' => $this->grand_total !== null ? (float) $this->grand_total : null,
            'actual_shipping_fee' => $this->actual_shipping_fee !== null ? (float) $this->actual_shipping_fee : null,
            'order_weight_gram' => $this->order_weight_gram !== null ? (float) $this->order_weight_gram : null,
            'transaction_date' => $this->transaction_date,
            'location_id' => $this->location_id,
            'location_name' => $this->whenLoaded('location', fn () => $this->location?->location_name),
            'tracking_number' => $this->tracking_number,
            'shipping_provider' => $this->shipping_provider,
            'is_cod' => (bool) $this->is_cod,
            'priority_fulfillment' => (bool) $this->priority_fulfillment,
            'is_split_order' => (bool) $this->is_split_order,
            'channel_status' => $this->channel_status,
            'is_canceled' => (bool) $this->is_canceled,
            'cancel_requested_at' => $this->cancel_requested_at,
            'cancel_reason' => $this->cancel_reason,
            'cancel_accepted_at' => $this->cancel_accepted_at,
            'ship_by_date' => $this->ship_by_date,
            'pickup_done_time' => $this->pickup_done_time,
            'days_to_ship' => $this->days_to_ship,
            'is_instant' => (bool) $this->is_instant,
            'shipping_type' => $this->shipping_type,
            'driver_call_status' => $this->driver_call_status,
            'driver_call_message' => $this->driver_call_message,
            'driver_call_attempted_at' => $this->driver_call_attempted_at,
            'total_qty' => $this->total_qty,
            'total_sku' => $this->total_sku,
            'dropshipper_name' => $this->dropshipper_name,
            'dropshipper_phone' => $this->dropshipper_phone,
            'picker_name' => $this->picker_name,
            'picklist_id' => $this->picklist_id,
            'picklist_no' => $this->picklist_no,
            'packer_name' => $this->packer_name,
            'invoice_id' => $this->invoice_id,
            'invoice_no' => $this->invoice_no,
            'items' => $this->whenLoaded(
                'items',
                fn () => $this->items->map(fn ($item) => [
                    'id' => $item->id,
                    'sku' => $item->sku,
                    'description' => $item->description,
                    'qty_in_base' => (int) $item->qty_in_base,
                    'bundle_components' => $item->bundle_components ?? null,
                ])->values(),
                [],
            ),
        ];
    }
}
