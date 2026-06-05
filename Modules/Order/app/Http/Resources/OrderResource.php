<?php

namespace Modules\Order\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'OrderResource',
    title: 'Order Resource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1),
        new OA\Property(property: 'salesorder_no', type: 'string', example: 'SO-12345'),
        new OA\Property(property: 'channel_shop_id', type: 'integer', example: 1),
        new OA\Property(property: 'customer_name', type: 'string', example: 'John Doe'),
        new OA\Property(property: 'transaction_date', type: 'string', format: 'date-time', example: '2026-06-04T12:00:00Z'),
        new OA\Property(property: 'sub_total', type: 'number', format: 'float', example: 100.00),
        new OA\Property(property: 'total_disc', type: 'number', format: 'float', example: 10.00),
        new OA\Property(property: 'total_tax', type: 'number', format: 'float', example: 5.00),
        new OA\Property(property: 'shipping_cost', type: 'number', format: 'float', example: 15.00),
        new OA\Property(property: 'insurance_cost', type: 'number', format: 'float', example: 2.00),
        new OA\Property(property: 'grand_total', type: 'number', format: 'float', example: 112.00),
        new OA\Property(property: 'shipping_full_name', type: 'string', example: 'John Doe'),
        new OA\Property(property: 'shipping_phone', type: 'string', example: '+6281234567890'),
        new OA\Property(property: 'shipping_address', type: 'string', example: 'Jl. Sudirman No. 1'),
        new OA\Property(property: 'shipping_area', type: 'string', example: 'Kebayoran Baru'),
        new OA\Property(property: 'shipping_city', type: 'string', example: 'Jakarta Selatan'),
        new OA\Property(property: 'shipping_province', type: 'string', example: 'DKI Jakarta'),
        new OA\Property(property: 'shipping_post_code', type: 'string', example: '12190'),
        new OA\Property(property: 'shipping_country', type: 'string', example: 'Indonesia'),
        new OA\Property(property: 'status', type: 'string', example: 'pending'),
        new OA\Property(property: 'is_paid', type: 'boolean', example: false),
        new OA\Property(property: 'is_canceled', type: 'boolean', example: false),
        new OA\Property(property: 'cancel_reason', type: 'string', example: null),
        new OA\Property(property: 'channel_status', type: 'string', example: 'new'),
        new OA\Property(property: 'payment_method', type: 'string', example: 'bank_transfer'),
        new OA\Property(property: 'source', type: 'string', example: 'api'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', example: '2026-06-04T12:00:00Z'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', example: '2026-06-04T12:00:00Z'),
        new OA\Property(property: 'items', type: 'array', items: new OA\Items(type: 'object'))
    ]
)]
class OrderResource extends JsonResource
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
            'salesorder_no' => $this->salesorder_no,
            'channel_shop_id' => $this->channel_shop_id,
            'customer_name' => $this->customer_name,
            'transaction_date' => $this->transaction_date,
            'sub_total' => $this->sub_total,
            'total_disc' => $this->total_disc,
            'total_tax' => $this->total_tax,
            'shipping_cost' => $this->shipping_cost,
            'insurance_cost' => $this->insurance_cost,
            'grand_total' => $this->grand_total,
            'shipping_full_name' => $this->shipping_full_name,
            'shipping_phone' => $this->shipping_phone,
            'shipping_address' => $this->shipping_address,
            'shipping_city' => $this->shipping_city,
            'shipping_province' => $this->shipping_province,
            'shipping_post_code' => $this->shipping_post_code,
            'shipping_country' => $this->shipping_country,
            'channel_status' => $this->channel_status,
            'status' => $this->status,
            'is_paid' => $this->is_paid,
            'is_canceled' => $this->is_canceled,
            'payment_method' => $this->payment_method,
            'source' => $this->source,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'items' => OrderItemResource::collection($this->whenLoaded('items')),
        ];
    }
}
