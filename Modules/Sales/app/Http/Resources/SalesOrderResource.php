<?php

namespace Modules\Sales\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Outbound\Support\InstantOrderClassifier;
use Modules\Sales\Enums\BuyerCancellationSyncStatus;
use Modules\Sales\Support\CancelReasonHumanizer;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'SalesOrderResource',
    title: 'Sales Order Resource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', example: 1),
        new OA\Property(property: 'salesorder_no', type: 'string', example: 'SO-12345'),
        new OA\Property(property: 'source', type: 'string', example: 'tiktok'),
        new OA\Property(property: 'commerce_platform', type: 'string', nullable: true, example: 'TOKOPEDIA'),
        new OA\Property(property: 'channel_shop_id', type: 'string', example: 1),
        new OA\Property(property: 'customer_name', type: 'string', example: 'John Doe'),
        new OA\Property(property: 'transaction_date', type: 'string', format: 'date-time', example: '2026-06-04T12:00:00Z'),

        new OA\Property(property: 'status', type: 'string', example: 'PENDING'),
        new OA\Property(property: 'channel_status', type: 'string', example: 'UNPAID'),
        new OA\Property(property: 'is_paid', type: 'boolean', example: false),
        new OA\Property(property: 'is_canceled', type: 'boolean', example: false),
        new OA\Property(property: 'has_stock_shortfall', type: 'boolean', example: false),
        new OA\Property(property: 'payment_method', type: 'string', nullable: true, example: null),

        new OA\Property(property: 'sub_total', type: 'number', format: 'float', example: 100000),
        new OA\Property(property: 'total_disc', type: 'number', format: 'float', example: 10000),
        new OA\Property(property: 'total_tax', type: 'number', format: 'float', example: 5000),
        new OA\Property(property: 'shipping_cost', type: 'number', format: 'float', example: 15000),
        new OA\Property(property: 'insurance_cost', type: 'number', format: 'float', example: 2000),
        new OA\Property(property: 'grand_total', type: 'number', format: 'float', example: 112000),

        new OA\Property(
            property: 'finance',
            type: 'object',
            description: 'Rincian keuangan. Field fee null = belum settle. is_settled=true berarti final dari escrow/finance channel.',
            properties: [
                new OA\Property(property: 'seller_voucher', type: 'number', format: 'float', nullable: true, example: 5000),
                new OA\Property(property: 'platform_voucher', type: 'number', format: 'float', nullable: true, example: 3000),
                new OA\Property(property: 'payment_voucher', type: 'number', format: 'float', nullable: true, example: 1500),
                new OA\Property(property: 'commission_fee', type: 'number', format: 'float', nullable: true, example: 2200),
                new OA\Property(property: 'service_fee', type: 'number', format: 'float', nullable: true, example: 1100),
                new OA\Property(property: 'transaction_fee', type: 'number', format: 'float', nullable: true, example: 800),
                new OA\Property(property: 'affiliate_commission', type: 'number', format: 'float', nullable: true, example: null),
                new OA\Property(property: 'order_processing_fee', type: 'number', format: 'float', nullable: true, example: 1250),
                new OA\Property(property: 'seller_shipping_borne', type: 'number', format: 'float', nullable: true, example: 0),
                new OA\Property(property: 'platform_shipping_rebate', type: 'number', format: 'float', nullable: true, example: 10000),
                new OA\Property(property: 'settlement_amount', type: 'number', format: 'float', nullable: true, example: 98900),
                new OA\Property(property: 'refund_total', type: 'number', format: 'float', example: 0, description: 'Total dana yang dikembalikan dari retur berstatus settlement'),
                new OA\Property(property: 'currency', type: 'string', example: 'IDR'),
                new OA\Property(property: 'is_settled', type: 'boolean', example: false),
                new OA\Property(property: 'synced_at', type: 'string', format: 'date-time', nullable: true, example: null),
            ]
        ),

        new OA\Property(
            property: 'shipping',
            type: 'object',
            properties: [
                new OA\Property(property: 'full_name', type: 'string', nullable: true, example: 'John Doe'),
                new OA\Property(property: 'phone', type: 'string', nullable: true, example: '+6281234567890'),
                new OA\Property(property: 'address', type: 'string', nullable: true, example: 'Jl. Sudirman No. 1'),
                new OA\Property(property: 'city', type: 'string', nullable: true, example: 'Jakarta Selatan'),
                new OA\Property(property: 'province', type: 'string', nullable: true, example: 'DKI Jakarta'),
                new OA\Property(property: 'post_code', type: 'string', nullable: true, example: '12190'),
                new OA\Property(property: 'country', type: 'string', nullable: true, example: 'Indonesia'),
            ]
        ),

        new OA\Property(property: 'items', type: 'array', items: new OA\Items(ref: '#/components/schemas/SalesOrderItemResource')),

        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', example: '2026-06-04T12:00:00Z'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', example: '2026-06-04T12:00:00Z'),
    ]
)]
class SalesOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'salesorder_no' => $this->salesorder_no,
            'channel_order_no' => $this->channel_order_no,
            'source' => $this->source,
            'commerce_platform' => $this->commerce_platform,
            'channel_shop_id' => $this->channel_shop_id,
            'shop_name' => $this->whenLoaded('shop', fn () => $this->shop?->shop_name),
            'customer_name' => $this->customer_name,
            'transaction_date' => $this->transaction_date,

            'status' => $this->status,
            'status_label' => $this->status_label,
            'wms_status' => $this->wms_status ?? $this->resolved_wms_status,
            'channel_status' => $this->channel_status,
            'channel_status_raw' => $this->channel_status_raw,
            'is_paid' => (bool) $this->is_paid,
            'is_canceled' => (bool) $this->is_canceled,
            'has_stock_shortfall' => $this->hasStockShortfall(),
            'cancel_reason' => $this->cancel_reason,
            'channel_cancel_status' => $this->channel_cancel_status,
            'channel_cancel_error' => $this->channel_cancel_error,
            'channel_cancel_requested_at' => $this->channel_cancel_requested_at,
            'payment_method' => $this->payment_method,
            'payment_method_name' => $this->payment_method_name,
            'paid_time' => $this->paid_time,
            'ship_by_date' => $this->ship_by_date,

            'sub_total' => (float) $this->sub_total,
            'total_disc' => (float) $this->total_disc,
            'total_tax' => (float) $this->total_tax,
            'shipping_cost' => (float) $this->shipping_cost,
            'insurance_cost' => (float) $this->insurance_cost,
            'grand_total' => (float) $this->grand_total,

            'finance' => [
                'seller_voucher' => $this->floatOrNull($this->seller_voucher),
                'platform_voucher' => $this->floatOrNull($this->platform_voucher),
                'payment_voucher' => $this->floatOrNull($this->payment_voucher),
                'commission_fee' => $this->floatOrNull($this->commission_fee),
                'service_fee' => $this->floatOrNull($this->service_fee),
                'transaction_fee' => $this->floatOrNull($this->transaction_fee),
                'affiliate_commission' => $this->floatOrNull($this->affiliate_commission),
                'order_processing_fee' => $this->floatOrNull($this->order_processing_fee),
                'seller_shipping_borne' => $this->floatOrNull($this->seller_shipping_borne),
                'platform_shipping_rebate' => $this->floatOrNull($this->platform_shipping_rebate),
                'settlement_amount' => $this->floatOrNull($this->settlement_amount),
                'refund_total' => $this->floatOrNull($this->refund_total) ?? $this->refundTotal(),
                'gross_amount' => $this->floatOrNull($this->gross_amount),
                'total_tax' => $this->floatOrNull($this->total_tax),
                'insurance_cost' => $this->floatOrNull($this->insurance_cost),
                'currency' => $this->fee_currency ?? 'IDR',
                'is_settled' => (bool) $this->is_settled,
                'settled_at' => $this->settled_at,
                'synced_at' => $this->finance_synced_at,
                'fee_lines' => $this->whenLoaded('feeLines', fn () => $this->feeLines->map(fn ($line) => [
                    'fee_type' => $line->fee_type,
                    'channel_fee_code' => $line->channel_fee_code,
                    'amount' => (float) $line->amount,
                ])->values(), []),
            ],

            'shipping' => [
                'full_name' => $this->shipping_full_name,
                'phone' => $this->shipping_phone,
                'address' => $this->shipping_address,
                'city' => $this->shipping_city,
                'province' => $this->shipping_province,
                'post_code' => $this->shipping_post_code,
                'country' => $this->shipping_country,
                'coordinate' => $this->shipping_coordinate,
                'provider' => $this->shipping_provider,
                'resolved_shipment_type' => $this->resolved_shipment_type,
                'courier' => $this->whenLoaded('courier', fn () => $this->courier ? [
                    'id' => $this->courier->id,
                    'code' => $this->courier->code,
                    'name' => $this->courier->name,
                ] : null),
                'tracking_number' => $this->tracking_number,
            ],

            'shipping_label' => [
                'status' => $this->shipping_label_status,
                'doc_type' => $this->shipping_label_doc_type,
                'prepared_at' => $this->shipping_label_prepared_at,
            ],

            'courier_pickup' => [
                'courier_name' => $this->courier_name,
                'courier_phone' => $this->courier_phone,
                'pickup_code' => $this->pickup_code,
                'id_photo_url' => $this->relationLoaded('media') && $this->media->contains('collection_name', 'courier_id')
                    ? ($this->getFirstMediaUrl('courier_id') ?: null)
                    : null,
                'id_photo_thumb' => $this->relationLoaded('media') && $this->media->contains('collection_name', 'courier_id')
                    ? ($this->getFirstMediaUrl('courier_id', 'thumb') ?: null)
                    : null,
                'recorded_at' => $this->courier_pickup_recorded_at,
                'recorded_by' => $this->courier_pickup_recorded_by,
            ],

            'buyer_message' => $this->buyer_message,
            'seller_note' => $this->seller_note,

            'location_id' => $this->location_id,
            'location_name' => $this->whenLoaded('location', fn () => $this->location?->location_name),
            'scheduled_shipment' => $this->whenLoaded('shipmentOrders', function () {
                $shipmentOrder = $this->shipmentOrders->first(
                    fn ($shipmentOrder): bool => $shipmentOrder->relationLoaded('shipment')
                        && $shipmentOrder->shipment?->status === 'SCHEDULED',
                );

                return $shipmentOrder?->shipment ? [
                    'id' => $shipmentOrder->shipment->id,
                    'shipment_no' => $shipmentOrder->shipment->shipment_no,
                    'status' => $shipmentOrder->shipment->status,
                    'shipment_date' => $shipmentOrder->shipment->shipment_date,
                    'courier_name' => $shipmentOrder->shipment->courier_name,
                    'courier_code' => $shipmentOrder->shipment->courier_code,
                ] : null;
            }),
            'total_qty' => $this->whenLoaded('items', fn () => $this->items->sum('qty_in_base'), 0),
            'total_sku' => $this->whenLoaded('items', fn () => $this->items->count(), 0),
            'has_unmapped_items' => $this->whenLoaded('items', fn () => $this->items->contains(fn ($item) => $item->item_id === null), false),
            'cancel_requested_at' => $this->cancel_requested_at,
            'cancel_request_reason' => CancelReasonHumanizer::buyer($this->cancel_request_reason),
            'cancel_reject_reason' => CancelReasonHumanizer::sellerReject($this->cancel_reject_reason),
            'buyer_cancel_sync_status' => $this->buyer_cancel_sync_status,
            'buyer_cancel_sync_status_label' => $this->buyer_cancel_sync_status
                ? BuyerCancellationSyncStatus::tryFrom($this->buyer_cancel_sync_status)?->label()
                : null,
            'buyer_cancel_sync_decision' => $this->buyer_cancel_sync_decision,
            'buyer_cancel_sync_error' => $this->buyer_cancel_sync_error,
            'buyer_cancel_synced_at' => $this->buyer_cancel_synced_at,
            'buyer_cancel_channel_reference' => $this->buyer_cancel_channel_reference,
            'handed_to_warehouse_at' => $this->handed_to_warehouse_at,

            'pick_failed_at' => $this->pick_failed_at,
            'pick_failed_by' => $this->pick_failed_by,
            'pick_fail_reason' => $this->pick_fail_reason,

            'contacted_at' => $this->contacted_at,
            'contacted_by' => $this->contacted_by,
            'contact_channel' => $this->contact_channel,
            'customer_decision' => $this->customer_decision,
            'decision_at' => $this->decision_at,
            'decision_by' => $this->decision_by,
            'contact_note' => $this->contact_note,

            'is_manual' => (bool) $this->is_manual,
            'is_shadow' => (bool) $this->is_shadow,
            'no_ref' => $this->no_ref,
            'note' => $this->note,
            'delivery_method' => $this->delivery_method,
            'is_cod' => (bool) $this->is_cod,
            'priority_fulfillment' => (bool) $this->priority_fulfillment,
            'is_instant' => InstantOrderClassifier::isInstant($this->shipping_provider, $this->shipping_type),
            'other_discount' => (float) ($this->other_discount ?? 0),
            'shipping_discount' => (float) ($this->shipping_discount ?? 0),
            'price_includes_tax' => (bool) $this->price_includes_tax,
            'order_weight_gram' => $this->order_weight_gram,

            'internal_store' => $this->whenLoaded('internalStore', fn () => $this->internalStore ? [
                'id' => $this->internalStore->id,
                'code' => $this->internalStore->code,
                'name' => $this->internalStore->name,
                'logo_url' => $this->internalStore->relationLoaded('media') && $this->internalStore->media->contains('collection_name', 'logo')
                    ? ($this->internalStore->getFirstMediaUrl('logo') ?: null)
                    : null,
                'logo_thumb' => $this->internalStore->relationLoaded('media') && $this->internalStore->media->contains('collection_name', 'logo')
                    ? ($this->internalStore->getFirstMediaUrl('logo', 'thumb') ?: null)
                    : null,
            ] : null),

            'salesman' => $this->whenLoaded('salesman', fn () => $this->salesman ? [
                'id' => $this->salesman->id,
                'code' => $this->salesman->code,
                'name' => $this->salesman->name,
            ] : null),

            'items' => SalesOrderItemResource::collection($this->whenLoaded('items')),

            'status_history' => $this->whenLoaded('statusHistory', fn () => $this->statusHistory->map(fn ($h) => [
                'action' => $h->action,
                'action_id' => $h->action_id,
                'actor_email' => $h->actor_email,
                'actor_name' => $h->actor_name,
                'created_at' => $h->created_at,
            ])->values()
            ),

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    private function floatOrNull($value): ?float
    {
        return $value === null ? null : (float) $value;
    }

    private function refundTotal(): float
    {
        if (! $this->relationLoaded('returns')) {
            return 0.0;
        }

        return (float) $this->returns->sum(
            fn ($return) => (float) ($return->settlement->total_amount ?? 0)
        );
    }
}
