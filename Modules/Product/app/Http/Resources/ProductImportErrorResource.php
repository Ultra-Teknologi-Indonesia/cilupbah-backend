<?php

namespace Modules\Product\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductImportErrorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'row_number' => $this->row_number,
            'attribute' => $this->attribute,
            'message' => $this->message,
            'row_snapshot' => $this->row_snapshot,
        ];
    }
}
