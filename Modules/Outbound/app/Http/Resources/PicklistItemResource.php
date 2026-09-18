<?php

namespace Modules\Outbound\Http\Resources;

use App\Enums\ClientChannelEnum;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class PicklistItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $product = $this->relationLoaded('product') ? $this->product : null;
        $order = $this->relationLoaded('order') ? $this->order : null;
        $bin = $this->relationLoaded('bin') ? $this->bin : null;
        $isMobile = $request->attributes->get('client_channel') === ClientChannelEnum::MOBILE
            || strtoupper((string) $request->header('X-Client-Channel')) === ClientChannelEnum::MOBILE->value;

        return [
            'id' => $this->id,
            'picklist_id' => $this->picklist_id,
            'sku' => $this->sku,
            'item_id' => $this->item_id,
            'order_id' => $this->order_id,
            'order_item_id' => $this->order_item_id,
            'bin_id' => $this->bin_id,
            'qty_ordered' => (int) $this->qty_ordered,
            'qty_picked' => (int) $this->qty_picked,
            'item_status' => $this->item_status ?? $this->effective_item_status,
            'status' => $this->item_status ?? $this->effective_item_status,
            'fail_reason_code' => $this->fail_reason_code,
            'fail_reason_note' => $this->fail_reason_note,
            'failed_qty' => $this->failed_qty,
            'last_picked_by_name' => $this->last_picked_by_name,
            'last_picked_at' => $this->last_picked_at,
            'image_url' => $product && $this->relationLoaded('product')
                ? $this->image_url
                : null,
            'product' => $product ? array_merge([
                'variant_name' => null,
                'product' => $product->relationLoaded('product') && $product->product ? array_merge([
                    'name' => $product->product->name,
                ], $isMobile ? [
                    'media' => $product->product->relationLoaded('media')
                        ? $product->product->media->map(fn ($media) => [
                            'url' => $media->url,
                            'is_primary' => (bool) $media->is_primary,
                            'media_type' => $media->media_type,
                        ])->values()
                        : [],
                ] : []) : null,
            ], $isMobile ? [
                'barcode' => $product->barcode,
                'media' => $product->relationLoaded('media')
                    ? $product->media->map(fn ($media) => [
                        'url' => $media->url,
                        'is_primary' => (bool) $media->is_primary,
                        'media_type' => $media->media_type,
                    ])->values()
                    : [],
            ] : []) : null,
            'recommended_bin_code' => $this->recommended_bin_code,
            'bin' => $bin ? [
                'bin_final_code' => $bin->bin_final_code,
                'bin_code' => $bin->bin_code,
            ] : null,
            'order' => $order ? [
                'salesorder_no' => $order->salesorder_no,
                'tracking_number' => $order->tracking_number,
                'source' => $order->source,
                'shipment_orders' => $order->relationLoaded('shipmentOrders')
                    ? $order->shipmentOrders->map(fn ($shipmentOrder) => [
                        'shipment' => $shipmentOrder->relationLoaded('shipment') && $shipmentOrder->shipment
                            ? ['shipment_no' => $shipmentOrder->shipment->shipment_no]
                            : null,
                    ])->values()
                    : [],
            ] : null,
        ];
    }
}
