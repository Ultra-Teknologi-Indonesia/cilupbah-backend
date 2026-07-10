<?php

namespace Modules\Sales\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SalesOrderImportErrorResource extends JsonResource
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
