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
            'transaction_date' => $this->transaction_date,
            'location_id' => $this->location_id,
            'location_name' => $this->whenLoaded('location', fn () => $this->location?->location_name),
            'tracking_number' => $this->tracking_number,
            'shipping_provider' => $this->shipping_provider,
            'is_canceled' => (bool) $this->is_canceled,
            'cancel_requested_at' => $this->cancel_requested_at,
            'ship_by_date' => $this->ship_by_date,
            'is_instant' => (bool) $this->is_instant,
            'shipping_type' => $this->shipping_type,
            'picker_name' => $this->picker_name,
            'picklist_id' => $this->picklist_id,
            'picklist_no' => $this->picklist_no,
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
