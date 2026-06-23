<?php

namespace Modules\Sales\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'SalesOrderItemResource',
    title: 'Sales Order Item Resource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', example: 1),
        new OA\Property(property: 'item_id', type: 'string', nullable: true, example: 5),
        new OA\Property(property: 'channel_product_id', type: 'string', nullable: true, example: 'TT_PROD_001'),
        new OA\Property(property: 'sku', type: 'string', nullable: true, example: 'SKU-001'),
        new OA\Property(property: 'description', type: 'string', nullable: true, example: 'Produk A'),
        new OA\Property(property: 'qty_in_base', type: 'integer', example: 2),
        new OA\Property(property: 'price', type: 'number', format: 'float', example: 50000),
        new OA\Property(property: 'disc', type: 'number', format: 'float', example: 0),
        new OA\Property(property: 'disc_amount', type: 'number', format: 'float', example: 0),
        new OA\Property(property: 'tax_amount', type: 'number', format: 'float', example: 5000),
        new OA\Property(property: 'amount', type: 'number', format: 'float', example: 100000),
    ]
)]
class SalesOrderItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->id,
            'item_id'            => $this->item_id,
            'is_mapped'          => $this->item_id !== null,
            'channel_product_id' => $this->channel_product_id,
            'sku'                => $this->sku,
            'description'        => $this->description,
            'qty_in_base'        => (int) $this->qty_in_base,
            'price'              => (float) $this->price,
            'disc'               => (float) $this->disc,
            'disc_amount'        => (float) $this->disc_amount,
            'tax_amount'         => (float) $this->tax_amount,
            'amount'             => (float) $this->amount,
            'image_url'          => $this->image_url,
        ];
    }
}
