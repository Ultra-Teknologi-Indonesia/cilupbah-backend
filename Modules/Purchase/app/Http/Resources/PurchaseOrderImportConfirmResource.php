<?php

namespace Modules\Purchase\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseOrderImportConfirmResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'created'    => $this->resource['created'] ?? 0,
            'failed'     => $this->resource['failed'] ?? 0,
            'po_numbers' => $this->resource['po_numbers'] ?? [],
            'errors'     => $this->resource['errors'] ?? [],
        ];
    }
}
