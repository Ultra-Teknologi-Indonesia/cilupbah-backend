<?php

namespace Modules\Sales\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'SalesOrderResource',
    title: 'Sales Order Resource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', example: 1),
        new OA\Property(property: 'salesorder_no', type: 'string', example: 'SO-12345'),
        new OA\Property(property: 'source', type: 'string', example: 'tiktok'),
        new OA\Property(property: 'channel_shop_id', type: 'string', example: 1),
        new OA\Property(property: 'customer_name', type: 'string', example: 'John Doe'),
        new OA\Property(property: 'transaction_date', type: 'string', format: 'date-time', example: '2026-06-04T12:00:00Z'),

        new OA\Property(property: 'status', type: 'string', example: 'PENDING'),
        new OA\Property(property: 'channel_status', type: 'string', example: 'UNPAID'),
        new OA\Property(property: 'is_paid', type: 'boolean', example: false),
        new OA\Property(property: 'is_canceled', type: 'boolean', example: false),
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
            'id'              => $this->id,
            'salesorder_no'   => $this->salesorder_no,
            'channel_order_no'=> $this->channel_order_no,
            'source'          => $this->source,
            'channel_shop_id' => $this->channel_shop_id,
            'shop_name'       => $this->whenLoaded('shop', fn () => $this->shop?->shop_name),
            'customer_name'   => $this->customer_name,
            'transaction_date'=> $this->transaction_date,

            'status'              => $this->status,
            'channel_status'      => $this->channel_status,
            'is_paid'             => (bool) $this->is_paid,
            'is_canceled'         => (bool) $this->is_canceled,
            'cancel_reason'       => $this->cancel_reason,
            'payment_method'      => $this->payment_method,
            'payment_method_name' => $this->payment_method_name,
            'paid_time'           => $this->paid_time,
            'ship_by_date'        => $this->ship_by_date,

            'sub_total'      => (float) $this->sub_total,
            'total_disc'     => (float) $this->total_disc,
            'total_tax'      => (float) $this->total_tax,
            'shipping_cost'  => (float) $this->shipping_cost,
            'insurance_cost' => (float) $this->insurance_cost,
            'grand_total'    => (float) $this->grand_total,

            'finance' => [
                'seller_voucher'           => $this->floatOrNull($this->seller_voucher),
                'platform_voucher'         => $this->floatOrNull($this->platform_voucher),
                'payment_voucher'          => $this->floatOrNull($this->payment_voucher),
                'commission_fee'           => $this->floatOrNull($this->commission_fee),
                'service_fee'              => $this->floatOrNull($this->service_fee),
                'transaction_fee'          => $this->floatOrNull($this->transaction_fee),
                'affiliate_commission'     => $this->floatOrNull($this->affiliate_commission),
                'order_processing_fee'     => $this->floatOrNull($this->order_processing_fee),
                'seller_shipping_borne'    => $this->floatOrNull($this->seller_shipping_borne),
                'platform_shipping_rebate' => $this->floatOrNull($this->platform_shipping_rebate),
                'settlement_amount'        => $this->floatOrNull($this->settlement_amount),
                'currency'                 => $this->fee_currency ?? 'IDR',
                'is_settled'               => (bool) $this->is_settled,
                'synced_at'                => $this->finance_synced_at,
            ],

            'shipping' => [
                'full_name'     => $this->shipping_full_name,
                'phone'         => $this->shipping_phone,
                'address'       => $this->shipping_address,
                'city'          => $this->shipping_city,
                'province'      => $this->shipping_province,
                'post_code'     => $this->shipping_post_code,
                'country'       => $this->shipping_country,
                'provider'      => $this->shipping_provider,
                'tracking_number' => $this->tracking_number,
            ],

            'buyer_message' => $this->buyer_message,
            'seller_note'   => $this->seller_note,

            'location_id'   => $this->location_id,
            'location_name' => $this->whenLoaded('location', fn () => $this->location?->location_name),
            'total_qty'     => $this->whenLoaded('items', fn () => $this->items->sum('qty_in_base'), 0),
            'total_sku'     => $this->whenLoaded('items', fn () => $this->items->count(), 0),
            'has_unmapped_items' => $this->whenLoaded('items', fn () => $this->items->contains(fn ($item) => $item->item_id === null), false),
            'cancel_requested_at'    => $this->cancel_requested_at,
            'cancel_request_reason'  => $this->cancel_request_reason,

            'items' => SalesOrderItemResource::collection($this->whenLoaded('items')),

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    private function floatOrNull($value): ?float
    {
        return $value === null ? null : (float) $value;
    }
}
