<?php

namespace Modules\Product\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Product\Models\ProductImportRow;

/**
 * @mixin ProductImportRow
 * @property ProductImportRow $resource
 */
class ProductImportRowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var ProductImportRow $row */
        $row = $this->resource;

        return [
            'id' => $row->id,
            'import_batch_id' => $row->import_batch_id,
            'row_number' => $row->row_number,
            'sku' => $row->sku,
            'name' => $row->name,
            'category_name' => $row->category_name,
            'sell_price' => $row->sell_price,
            'status' => $row->status,
            'message' => $row->message,
            'payload' => $row->payload,
            'created_at' => $row->created_at,
        ];
    }
}
